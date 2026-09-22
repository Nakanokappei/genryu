{{-- Asked of a signed-in user before a secure setting: shown inside the app with the settings menu, so the sidebar stays and the user is not left with the browser's back button. --}}
<x-layouts::app :title="__('Confirm password')">
    <x-pages::settings.layout :heading="__('Confirm password')" :subheading="__('This is a secure area of the application. Please confirm your password before continuing.')">
        <div class="flex flex-col gap-6">
            <x-auth-session-status :status="session('status')" />

            <x-passkey-verify
                options-route="passkey.confirm-options"
                submit-route="passkey.confirm"
                :label="__('Confirm with passkey')"
                :loading-label="__('Confirming...')"
                :separator="__('Or confirm with password')"
            />

            <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
                @csrf

                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                <flux:button variant="primary" type="submit" class="w-full" data-test="confirm-password-button">
                    {{ __('Confirm') }}
                </flux:button>
            </form>
        </div>
    </x-pages::settings.layout>
</x-layouts::app>
