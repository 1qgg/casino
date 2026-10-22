<x-filament-panels::page>
    <div class="grid gap-6 xl:grid-cols-2">
        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <form wire:submit="submit" class="space-y-5">
                {{ $this->form }}
                <x-filament::button type="submit">Salvar credenciais</x-filament::button>
            </form>
        </section>
        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Catálogo 1Q</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Pesquise os jogos disponíveis. Use Provider e Game para mapear cada jogo em “Todos os Jogos”.</p>
            <form wire:submit="refreshCatalog" class="mt-4 flex gap-2">
                <input type="search" wire:model="catalogSearch" placeholder="Buscar jogo por nome ou código" class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                <x-filament::button type="submit">Consultar</x-filament::button>
            </form>
            @if ($catalogError)
                <p class="mt-4 rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-300">{{ $catalogError }}</p>
            @endif
            @if (count($catalogResults))
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-700">
                            <tr><th class="px-2 py-3">Jogo</th><th class="px-2 py-3">Provider</th><th class="px-2 py-3">Game</th><th class="px-2 py-3">Lançável</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($catalogResults as $item)
                                <tr wire:key="oneq-catalog-{{ md5(($item['provider'] ?? '').':'.($item['game'] ?? '')) }}">
                                    <td class="px-2 py-3 font-medium text-gray-950 dark:text-white">{{ $item['name'] ?? '—' }}</td>
                                    <td class="px-2 py-3">{{ $item['provider'] ?? '—' }}</td>
                                    <td class="px-2 py-3 font-mono">{{ $item['game'] ?? '—' }}</td>
                                    <td class="px-2 py-3">{{ ($item['launchable'] ?? false) ? 'Sim' : 'Não' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @elseif (!$catalogError)
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">Consulte o catálogo para ver os resultados aqui.</p>
            @endif
        </section>
    </div>
</x-filament-panels::page>
