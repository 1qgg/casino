<?php

namespace App\Filament\Pages;

use App\Models\GamesKey;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;

class GamesKeyPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static string $view = 'filament.pages.games-key-page';
    protected static ?string $title = 'Provedor de Jogos';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public ?array $data = [];
    public ?GamesKey $setting = null;

    public function mount(): void
    {
        $this->setting = GamesKey::query()->first();
        $values = $this->setting?->toArray() ?? [];
        $values['oneq_operator_secret'] = '';
        $values['oneq_callback_secret'] = '';
        $values['oneq_callback_url'] = url('/oneq/callback');
        $values['oneq_api_base'] = 'https://api.1q.gg';
        $this->form->fill($values);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Conexão 1Q Games')
                    ->description('Credenciais usadas para consultar o catálogo e abrir sessões. A URL base da API é fixa.')
                    ->schema([
                        TextInput::make('oneq_operator_key')
                            ->label('Operator Key')
                            ->required()
                            ->maxLength(191),
                        TextInput::make('oneq_operator_secret')
                            ->label('Operator Secret')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn ($state) => filled($state))
                            ->helperText('Deixe em branco para manter a credencial já salva.'),
                        TextInput::make('oneq_callback_secret')
                            ->label('Callback Secret')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn ($state) => filled($state))
                            ->helperText('Deixe em branco para manter a credencial já salva.'),
                        TextInput::make('oneq_callback_url')
                            ->label('URL de callback')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('oneq_api_base')
                            ->label('API base')
                            ->disabled()
                            ->dehydrated(false),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        try {
            if (env('APP_DEMO')) {
                Notification::make()->title('Atenção')
                    ->body('Você não pode realizar esta alteração na versão demo')
                    ->danger()->send();
                return;
            }

            $data = $this->form->getState();
            unset($data['oneq_callback_url'], $data['oneq_api_base']);
            foreach (['oneq_operator_secret', 'oneq_callback_secret'] as $secret) {
                if (blank($data[$secret] ?? null)) {
                    unset($data[$secret]);
                }
            }

            $setting = GamesKey::query()->first();
            if ($setting) {
                $setting->update($data);
            } else {
                GamesKey::query()->create($data);
            }

            Notification::make()->title('Credenciais salvas')
                ->body('As configurações da 1Q foram atualizadas.')
                ->success()->send();

            $this->mount();
        } catch (Halt $exception) {
            Notification::make()->title('Erro ao salvar credenciais')
                ->body('Não foi possível atualizar as configurações.')
                ->danger()->send();
        }
    }
}
