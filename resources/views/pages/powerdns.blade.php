<x-filament-panels::page>
    <div class="space-y-6">
        <div class="space-y-4">
            <p class="text-gray-600 dark:text-gray-400">
                When enabled, we can automatically update DNS records (such as DKIM) via the PowerDNS API.
            </p>
        </div>

        <form wire:submit="save">
            {{ $this->form }}

            <div class="flex justify-end mt-4">
                <x-filament::button type="submit">
                    Save Settings
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>