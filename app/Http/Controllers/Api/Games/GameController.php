<?php

namespace App\Http\Controllers\Api\Games;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameFavorite;
use App\Models\GameLike;
use App\Models\GamesKey;
use App\Models\Gateway;
use App\Models\Provider;
use App\Models\Wallet;
use App\Services\OneQGamesClient;
use Illuminate\Support\Str;


use Illuminate\Http\Request;

class GameController extends Controller
{


    /**
     * @dev victormsalatiel
     * Display a listing of the resource.
     */
    public function index()
    {
        $providers = Provider::with(['games', 'games.provider'])
            ->whereHas('games')
            ->orderBy('name', 'desc')
            ->where('status', 1)
            ->get();

        return response()->json(['providers' => $providers]);
    }

    /**
     * @dev victormsalatiel
     * @return \Illuminate\Http\JsonResponse
     */
    public function featured()
    {
        $featured_games = Game::with(['provider'])
            ->where('is_featured', 1)
            ->where('status', 1)
            ->get();

        return response()->json(['featured_games' => $featured_games]);
    }

    /**
     * Source Provider
     *
     * @dev victormsalatiel
     * @param Request $request
     * @param $token
     * @param $action
     * @return \Illuminate\Http\JsonResponse|void
     */
    public function sourceProvider(Request $request, $token, $action)
    {
        $tokenOpen = \Helper::DecToken($token);
        $validEndpoints = ['session', 'icons', 'spin', 'freenum'];

        if (in_array($action, $validEndpoints)) {
            if (isset($tokenOpen['status']) && $tokenOpen['status']) {
                $game = Game::whereStatus(1)->where('game_code', $tokenOpen['game'])->first();
                if (!empty($game)) {
                    $controller = \Helper::createController($game->game_code);

                    switch ($action) {
                        case 'session':
                            return $controller->session($token);
                        case 'spin':
                            return $controller->spin($request, $token);
                        case 'freenum':
                            return $controller->freenum($request, $token);
                        case 'icons':
                            return $controller->icons();
                    }
                }
            }
        } else {
            return response()->json([], 500);
        }
    }

    /**
     * @dev victormsalatiel
     * Store a newly created resource in storage.
     */
    public function toggleFavorite($id)
    {
        if (auth('api')->check()) {
            $checkExist = GameFavorite::where('user_id', auth('api')->id())->where('game_id', $id)->first();
            if (!empty($checkExist)) {
                if ($checkExist->delete()) {
                    return response()->json(['status' => true, 'message' => 'Removido com sucesso']);
                }
            } else {
                $gameFavoriteCreate = GameFavorite::create([
                    'user_id' => auth('api')->id(),
                    'game_id' => $id
                ]);

                if ($gameFavoriteCreate) {
                    return response()->json(['status' => true, 'message' => 'Criado com sucesso']);
                }
            }
        }
    }

    /**
     * @dev victormsalatiel
     * Store a newly created resource in storage.
     */
    public function toggleLike($id)
    {
        if (auth('api')->check()) {
            $checkExist = GameLike::where('user_id', auth('api')->id())->where('game_id', $id)->first();
            if (!empty($checkExist)) {
                if ($checkExist->delete()) {
                    return response()->json(['status' => true, 'message' => 'Removido com sucesso']);
                }
            } else {
                $gameLikeCreate = GameLike::create([
                    'user_id' => auth('api')->id(),
                    'game_id' => $id
                ]);

                if ($gameLikeCreate) {
                    return response()->json(['status' => true, 'message' => 'Criado com sucesso']);
                }
            }
        }
    }

    /**
     * @dev victormsalatiel
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $game = Game::with(['categories', 'provider'])->whereStatus(1)->find($id);
        if (!empty($game)) {
            $wallet = null;
            $token = null;

            if (auth('api')->check()) {
                $wallet = Wallet::where('user_id', auth('api')->id())->first();
                $token = \Helper::MakeToken([
                    'id' => auth('api')->id(),
                    'game' => $game->game_code
                ]);
            }

            $game->increment('views');

            switch ($game->distribution) {
                case 'oneq':
                    if (blank($game->oneq_provider) || blank($game->oneq_game)) {
                        return response()->json([
                            'status' => false,
                            'error' => 'Este jogo ainda não está mapeado para o catálogo 1Q.',
                        ], 422);
                    }

                    $user = auth('api')->user();
                    if (!$user || !$wallet) {
                        return response()->json(['status' => false, 'error' => 'Autenticação necessária.'], 401);
                    }

                    try {
                        $settings = GamesKey::query()->firstOrFail();
                        $client = new OneQGamesClient($settings);
                        $response = $client->launch([
                            'user_code' => (string) $user->email,
                            'game_code' => (string) $game->oneq_game,
                            'provider' => (string) $game->oneq_provider,
                            'game_original' => true,
                            'user_balance' => number_format((float) $wallet->total_balance, 4, '.', ''),
                            'currency' => strtoupper((string) ($wallet->currency ?: 'BRL')),
                            'lang' => str_replace('_', '-', app()->getLocale() ?: 'pt-BR'),
                            'return_url' => url('/'),
                            'product_type' => 'casino',
                        ], (string) Str::uuid());

                        $launchUrl = data_get($response, 'data.launch_url');
                        if (!is_string($launchUrl) || !filter_var($launchUrl, FILTER_VALIDATE_URL)) {
                            return response()->json(['status' => false, 'error' => 'A API 1Q não retornou uma URL de lançamento válida.'], 502);
                        }

                        return response()->json([
                            'game' => $game,
                            'gameUrl' => $launchUrl,
                            'token' => $token,
                        ]);
                    } catch (\Throwable $exception) {
                        report($exception);
                        return response()->json([
                            'status' => false,
                            'error' => 'Não foi possível abrir o jogo pela 1Q. Verifique as credenciais e tente novamente.',
                        ], 502);
                    }
                case 'play_fiver':
                    return response()->json([
                        'status' => false,
                        'error' => 'A integração PlayFiver foi substituída pela 1Q. Atualize o mapeamento deste jogo.',
                    ], 410);
            }
        }
        return response()->json(['error' => '', 'status' => false], 400);
    }

    /**
     * @dev victormsalatiel
     * Show the form for editing the specified resource.
     */
    public function allGames(Request $request)
    {
        $query = Game::query();
        $query->with(['provider', 'categories']);

        if (!empty($request->provider) && $request->provider != 'all') {
            $query->where('provider_id', $request->provider);
        }

        if (!empty($request->category) && $request->category != 'all') {
            $query->whereHas('categories', function ($categoryQuery) use ($request) {
                $categoryQuery->where('slug', $request->category);
            });
        }

        if (isset($request->searchTerm) && !empty($request->searchTerm) && strlen($request->searchTerm) > 2) {
            $query->whereLike(['game_code', 'game_name', 'description', 'distribution', 'provider.name'], $request->searchTerm);
        } else {
            $query->orderBy('views', 'desc');
        }

        $games = $query
            ->where('status', 1)
            ->paginate(12)->appends(request()->query());

        return response()->json(['games' => $games]);
    }




    public function webhookOneQ(Request $request)
    {
        // Fail closed: the public Games API documentation does not define the
        // project's wallet callback contract, signature headers, or transaction semantics.
        // Never change a balance until that contract is explicitly configured and verified.
        return response()->json([
            'status' => false,
            'error' => 'Contrato financeiro de callback não habilitado para esta integração.',
        ], 503);
    }


}
