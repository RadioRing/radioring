{{--
    A Bootstrap modal driven by a Livewire component: the component dispatches the open
    and close events, the dialog stays where the user is instead of a form at the top of
    the page. "dismiss" runs when the user closes it via X, backdrop or Escape, so the
    component can drop its half-edited state.
--}}
@props(['openEvent', 'closeEvent', 'dismiss' => null, 'size' => 'lg'])

<div {{ $attributes->merge(['class' => 'modal fade']) }} tabindex="-1" wire:ignore.self
     x-data="{ modal: null }"
     x-init="
        modal = bootstrap.Modal.getOrCreateInstance($el);
        $el.addEventListener('hidden.bs.modal', () => { {{ $dismiss }} });
        $el.addEventListener('shown.bs.modal', () => $el.querySelector('[autofocus]')?.focus());
     "
     x-on:{{ $openEvent }}.window="modal.show()"
     x-on:{{ $closeEvent }}.window="modal.hide()">
    <div @class(['modal-dialog modal-dialog-centered modal-dialog-scrollable', 'modal-'.$size => $size])>
        <div class="modal-content">
            {{ $slot }}
        </div>
    </div>
</div>
