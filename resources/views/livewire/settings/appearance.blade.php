<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-settings.layout :heading="__('Appearance')" :subheading=" __('Update the appearance settings for your account')">
        <button type="button" class="btn ghost" data-theme-toggle>{{ __('Cambiar tema') }}</button>
    </x-settings.layout>
</section>
