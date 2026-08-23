<section class="mt-5 pt-4 border-top">
    <h5 class="fw-semibold text-danger">{{ __('Delete account') }}</h5>
    <p class="text-muted-sm">{{ __('Delete your account and all of its resources') }}</p>

    <button type="button" class="btn btn-danger"
            data-bs-toggle="modal" data-bs-target="#confirmDeletionModal">
        {{ __('Delete account') }}
    </button>

    <div class="modal fade" id="confirmDeletionModal" tabindex="-1"
         aria-labelledby="confirmDeletionLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" wire:submit="deleteUser">
                    <div class="modal-header">
                        <h5 class="modal-title" id="confirmDeletionLabel">
                            {{ __('Are you sure you want to delete your account?') }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted-sm">
                            {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
                        </p>
                        <div class="mb-3">
                            <label for="delete_password" class="form-label">{{ __('Password') }}</label>
                            <input id="delete_password" type="password" wire:model="password"
                                   class="form-control @error('password') is-invalid @enderror">
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            {{ __('Cancel') }}
                        </button>
                        <button type="submit" class="btn btn-danger">
                            {{ __('Delete account') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
