<x-layouts.app :title="__('Profile')">
    <div class="max-w-3xl mx-auto p-6 space-y-6">
        <h1 class="text-2xl font-bold">{{ __('Profile') }}</h1>

        <div class="p-6 bg-white border rounded-lg">
            <livewire:profile.update-profile-information-form />
        </div>

        <div class="p-6 bg-white border rounded-lg">
            <livewire:profile.update-password-form />
        </div>

        <div class="p-6 bg-white border rounded-lg">
            <livewire:profile.delete-user-form />
        </div>
    </div>
</x-layouts.app>
