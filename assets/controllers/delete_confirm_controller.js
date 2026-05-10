import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['description'];

    _resolve = null;

    connect() {
        document.addEventListener('turbo:confirm', this.onConfirmRequest);
    }

    disconnect() {
        document.removeEventListener('turbo:confirm', this.onConfirmRequest);
    }

    onConfirmRequest = ({ detail: { message, resolve } }) => {
        this.descriptionTarget.textContent = message;
        this.element.classList.remove('hidden');
        this._resolve = resolve;
    }

    confirm() {
        if (this._resolve) this._resolve(true);
        this.close();
    }

    cancel() {
        if (this._resolve) this._resolve(false);
        this.close();
    }

    close() {
        this.element.classList.add('hidden');
        this._resolve = null;
    }
}
