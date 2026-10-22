<?php

namespace App\Services;

use App\Helpers\Core as Helper;
use App\Models\GamesKey;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OneQFinancialCallbackService
{
    public function handle(Request $request): JsonResponse
    {
        $settings = GamesKey::query()->first();
        if (!$settings || blank($settings->oneq_callback_key) || blank($settings->oneq_callback_secret)) {
            return response()->json(['accepted' => false, 'error_code' => 'CALLBACK_NOT_CONFIGURED'], 503);
        }

        $key = trim((string) $request->header('X-1Q-Key'));
        $timestamp = trim((string) $request->header('X-1Q-Timestamp'));
        $nonce = trim((string) $request->header('X-1Q-Nonce'));
        $signature = trim((string) $request->header('X-1Q-Signature'));
        $idempotencyKey = trim((string) $request->header('X-1Q-Idempotency-Key'));
        $payload = $request->json()->all();

        if (!hash_equals((string) $settings->oneq_callback_key, $key)
            || !ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > 300
            || $nonce === '' || strlen($nonce) > 128
            || $idempotencyKey === '' || strlen($idempotencyKey) > 128
            || $signature === '' || !is_array($payload)) {
            return response()->json(['accepted' => false, 'error_code' => 'INVALID_CALLBACK_AUTH'], 401);
        }

        try {
            $canonicalPayload = $this->canonicalJson($payload);
            $canonical = "POST\n".$request->getPathInfo()."\n".$timestamp."\n".$nonce."\n".$idempotencyKey."\n".hash('sha256', $canonicalPayload);
            $expected = hash_hmac('sha256', $canonical, (string) $settings->oneq_callback_secret);
            if (!hash_equals($expected, $signature)) {
                return response()->json(['accepted' => false, 'error_code' => 'INVALID_SIGNATURE'], 401);
            }

            DB::table('oneq_callback_nonces')->where('expires_at', '<=', now())->delete();
            try {
                DB::table('oneq_callback_nonces')->insert([
                    'nonce' => $nonce,
                    'expires_at' => now()->addMinutes(5),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (Throwable) {
                return response()->json(['accepted' => false, 'error_code' => 'NONCE_REPLAYED'], 409);
            }

            [$status, $body] = DB::transaction(function () use ($payload, $idempotencyKey): array {
                $payloadHash = hash('sha256', $this->canonicalJson($payload));
                DB::table('oneq_callback_requests')->insertOrIgnore([
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'status' => 'processing',
                    'response' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $record = DB::table('oneq_callback_requests')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if (!$record || !hash_equals((string) $record->payload_hash, $payloadHash)) {
                    return [409, ['accepted' => false, 'error_code' => 'IDEMPOTENCY_CONFLICT']];
                }
                if ($record->status === 'completed' && is_string($record->response)) {
                    return [200, json_decode($record->response, true) ?: ['accepted' => false, 'error_code' => 'STORED_RESPONSE_INVALID']];
                }

                $result = $this->process($payload);
                $status = $result[0];
                $body = $result[1];
                DB::table('oneq_callback_requests')->where('idempotency_key', $idempotencyKey)->update([
                    'status' => 'completed',
                    'response' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);

                return [$status, $body];
            }, 3);

            return response()->json($body, $status);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['accepted' => false, 'error_code' => 'CALLBACK_PROCESSING_ERROR'], 500);
        }
    }

    private function process(array $payload): array
    {
        $type = strtoupper((string) ($payload['type'] ?? ''));
        if ($type === 'WINBET') {
            return $this->winBet($payload);
        }
        if ($type === 'BALANCE') {
            $user = $this->findUser($payload['user_code'] ?? null);
            if (!$user) return [404, ['accepted' => false, 'error_code' => 'INVALID_USER']];
            $wallet = Wallet::query()->where('user_id', $user->id)->first();
            if (!$wallet) return [404, ['accepted' => false, 'error_code' => 'WALLET_NOT_FOUND']];
            if (!$this->currencyMatches($wallet, $payload['currency'] ?? null)) return [422, ['accepted' => false, 'error_code' => 'CURRENCY_MISMATCH']];
            return [200, ['accepted' => true, 'balance' => $this->money($wallet->total_balance)]];
        }
        if (!in_array($type, ['BET', 'WIN', 'REFUND'], true)) {
            return [422, ['accepted' => false, 'error_code' => 'UNSUPPORTED_OPERATION']];
        }

        $transaction = $payload['transaction'] ?? null;
        if (!is_array($transaction)) return [422, ['accepted' => false, 'error_code' => 'INVALID_TRANSACTION']];
        $user = $this->findUser($payload['user_code'] ?? null);
        if (!$user) return [404, ['accepted' => false, 'error_code' => 'INVALID_USER']];
        $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->first();
        if (!$wallet) return [404, ['accepted' => false, 'error_code' => 'WALLET_NOT_FOUND']];
        if (!$this->currencyMatches($wallet, $transaction['currency'] ?? null)) return [422, ['accepted' => false, 'error_code' => 'CURRENCY_MISMATCH']];

        $amount = $this->positiveAmount($transaction['amount'] ?? null);
        $transactionId = (string) ($transaction['id'] ?? '');
        $roundId = (string) ($transaction['round_id'] ?? '');
        $gameCode = (string) ($transaction['game_code'] ?? '');
        $providerCode = (string) ($transaction['provider_code'] ?? '');
        if ($amount === null || $transactionId === '' || $roundId === '' || $gameCode === '') {
            return [422, ['accepted' => false, 'error_code' => 'INVALID_TRANSACTION']];
        }

        if ($type !== 'REFUND') {
            $existing = Order::query()->where('providers', 'oneq')->where('transaction_id', $transactionId)->first();
            if ($existing) {
                return [200, ['accepted' => true, 'balance_before' => $this->money($wallet->total_balance), 'balance_after' => $this->money($wallet->total_balance), 'transaction_id' => $transactionId, 'replayed' => true]];
            }
        }

        $before = (float) $wallet->total_balance;
        $changeBucket = 'balance_withdrawal';

        if ($type === 'BET') {
            $changeBucket = $this->debit($wallet, $amount);
            if ($changeBucket === null) return [409, ['accepted' => false, 'error_code' => 'INSUFFICIENT_FUNDS']];
            $this->createOrder($user->id, $transactionId, $gameCode, $roundId, 'bet', $amount, $changeBucket, false);
            Helper::payWithRollover($user->id, $changeBucket, 0, $amount, 'bet');
            Helper::generateGameHistory($user->id, 'bet', 0, $amount, $changeBucket, $transactionId);
        } elseif ($type === 'WIN') {
            $this->credit($wallet, $amount);
            $this->createOrder($user->id, $transactionId, $gameCode, $roundId, 'win', $amount, $changeBucket, false);
            Helper::payWithRollover($user->id, $changeBucket, $amount, 0, 'win');
            Helper::generateGameHistory($user->id, 'win', $amount, 0, $changeBucket, $transactionId);
        } else {
            $original = Order::query()->where('providers', 'oneq')
                ->where('refunded', false)
                ->where(function ($query) use ($transactionId, $roundId) {
                    $query->where('transaction_id', $transactionId)->orWhere('round_id', $roundId);
                })->lockForUpdate()->first();
            if (!$original) return [409, ['accepted' => false, 'error_code' => 'REFUND_TRANSACTION_NOT_FOUND']];
            $this->credit($wallet, $amount);
            $original->update(['refunded' => true]);
            $this->createOrder($user->id, $transactionId, $gameCode, $roundId, 'win', $amount, $changeBucket, true);
        }

        $wallet->refresh();
        return [200, [
            'accepted' => true,
            'status' => 'accepted',
            'balance_before' => $this->money($before),
            'balance_after' => $this->money($wallet->total_balance),
            'transaction_id' => $transactionId,
        ]];
    }

    private function winBet(array $payload): array
    {
        $slot = $payload['slot'] ?? null;
        if (!is_array($slot)) return [422, ['accepted' => false, 'error_code' => 'INVALID_TRANSACTION']];
        $user = $this->findUser($payload['user_code'] ?? null);
        if (!$user) return [404, ['accepted' => false, 'error_code' => 'INVALID_USER']];
        $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->first();
        if (!$wallet) return [404, ['accepted' => false, 'error_code' => 'WALLET_NOT_FOUND']];
        if (!$this->currencyMatches($wallet, $slot['currency'] ?? null)) return [422, ['accepted' => false, 'error_code' => 'CURRENCY_MISMATCH']];

        $transactionId = (string) ($slot['txn_id'] ?? '');
        $roundId = (string) ($slot['round_id'] ?? '');
        $gameCode = (string) ($slot['game_code'] ?? '');
        $bet = $this->nonNegativeAmount($slot['bet'] ?? null);
        $win = $this->nonNegativeAmount($slot['win'] ?? null);
        if ($transactionId === '' || $roundId === '' || $gameCode === '' || $bet === null || $win === null || $bet <= 0) {
            return [422, ['accepted' => false, 'error_code' => 'INVALID_TRANSACTION']];
        }

        $existing = Order::query()->where('providers', 'oneq')->where('transaction_id', $transactionId)->first();
        if ($existing) {
            return [200, ['accepted' => true, 'balance_before' => $this->money($wallet->total_balance), 'balance_after' => $this->money($wallet->total_balance), 'transaction_id' => $transactionId, 'replayed' => true]];
        }

        $before = (float) $wallet->total_balance;
        $changeBucket = $this->debit($wallet, $bet);
        if ($changeBucket === null) return [409, ['accepted' => false, 'error_code' => 'INSUFFICIENT_FUNDS']];
        if ($win > 0) {
            Helper::payWithRollover($user->id, $changeBucket, $win, $bet, 'win');
        }
        $this->createOrder($user->id, $transactionId, $gameCode, $roundId, $win > 0 ? 'win' : 'bet', $win > 0 ? $win : $bet, $changeBucket, false);
        Helper::generateGameHistory($user->id, $win > 0 ? 'win' : 'bet', $win, $bet, $changeBucket, $transactionId);
        $wallet->refresh();

        return [200, [
            'accepted' => true,
            'status' => 'accepted',
            'balance_before' => $this->money($before),
            'balance_after' => $this->money($wallet->total_balance),
            'transaction_id' => $transactionId,
        ]];
    }

    private function findUser(mixed $userCode): ?User
    {
        if (!is_string($userCode) || $userCode === '') return null;
        return User::query()->where('email', $userCode)->first();
    }

    private function currencyMatches(Wallet $wallet, mixed $currency): bool
    {
        if (!is_string($currency) || strlen($currency) !== 3) return false;
        return blank($wallet->currency) || strtoupper((string) $wallet->currency) === strtoupper($currency);
    }

    private function positiveAmount(mixed $value): ?float
    {
        $amount = $this->nonNegativeAmount($value);
        return $amount !== null && $amount > 0 ? $amount : null;
    }

    private function nonNegativeAmount(mixed $value): ?float
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) return null;
        if (!is_numeric($value) || (float) $value < 0 || !is_finite((float) $value)) return null;
        return round((float) $value, 4);
    }

    private function debit(Wallet $wallet, float $amount): ?string
    {
        $bonus = (float) $wallet->balance_bonus;
        $balance = (float) $wallet->balance;
        $withdrawal = (float) $wallet->balance_withdrawal;
        if ((float) $wallet->total_balance + 0.00001 < $amount) return null;

        if ($bonus >= $amount) {
            $wallet->decrement('balance_bonus', $amount);
            return 'balance_bonus';
        }
        if ($balance >= $amount) {
            $wallet->decrement('balance', $amount);
            return 'balance';
        }
        if ($withdrawal >= $amount) {
            $wallet->decrement('balance_withdrawal', $amount);
            return 'balance_withdrawal';
        }

        $remaining = $amount;
        foreach (['balance_bonus', 'balance', 'balance_withdrawal'] as $column) {
            $available = (float) $wallet->{$column};
            $deduct = min($available, $remaining);
            if ($deduct > 0) $wallet->decrement($column, $deduct);
            $remaining = round($remaining - $deduct, 4);
            if ($remaining <= 0.00001) break;
        }
        return 'balance';
    }

    private function credit(Wallet $wallet, float $amount): void
    {
        $wallet->increment('balance_withdrawal', $amount);
    }

    private function createOrder(int $userId, string $transactionId, string $gameCode, string $roundId, string $type, float $amount, string $bucket, bool $refunded): void
    {
        Order::query()->create([
            'user_id' => $userId,
            'session_id' => $roundId,
            'transaction_id' => $transactionId,
            'game' => $gameCode,
            'game_uuid' => $gameCode,
            'type' => $type,
            'type_money' => $bucket,
            'amount' => $amount,
            'providers' => 'oneq',
            'refunded' => $refunded,
            'round_id' => $roundId,
            'status' => true,
        ]);
    }

    private function canonicalJson(array $payload): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value);
            foreach ($value as $key => $item) $value[$key] = $sort($item);
            return $value;
        };

        return json_encode($sort($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 4, '.', '');
    }
}
