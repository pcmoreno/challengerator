import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this._overlay = document.createElement('div');
        this._overlay.className = 'hidden fixed inset-0 z-50 flex items-center justify-center bg-black/80 cursor-pointer';
        this._overlay.addEventListener('click', () => this.close());

        this._img = document.createElement('img');
        this._img.className = 'max-h-screen max-w-screen-lg object-contain rounded shadow-xl';
        this._overlay.appendChild(this._img);

        document.body.appendChild(this._overlay);

        this._onKeyDown = (e) => { if (e.key === 'Escape') this.close(); };
    }

    disconnect() {
        this._overlay.remove();
        document.removeEventListener('keydown', this._onKeyDown);
    }

    open(event) {
        this._img.src = event.currentTarget.src;
        this._overlay.classList.remove('hidden');
        document.addEventListener('keydown', this._onKeyDown);
    }

    close() {
        this._overlay.classList.add('hidden');
        document.removeEventListener('keydown', this._onKeyDown);
    }
}
