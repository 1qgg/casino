<?php

use App\Http\Controllers\Api\Games\GameController;
use Illuminate\Support\Facades\Route;

// Callback financeiro desabilitado até o contrato específico do projeto ser confirmado.
Route::post('/oneq/callback', [GameController::class, 'webhookOneQ']);
