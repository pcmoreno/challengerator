import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['userPanel', 'adminPanel', 'userTab', 'adminTab'];

    connect() {
        this.#activate(this.userTabTarget, this.adminTabTarget);
    }

    showUser() {
        this.userPanelTarget.classList.remove('hidden');
        this.adminPanelTarget.classList.add('hidden');
        this.#activate(this.userTabTarget, this.adminTabTarget);
    }

    showAdmin() {
        this.adminPanelTarget.classList.remove('hidden');
        this.userPanelTarget.classList.add('hidden');
        this.#activate(this.adminTabTarget, this.userTabTarget);
    }

    #activate(active, inactive) {
        active.classList.add('bg-header', 'text-white');
        active.classList.remove('text-muted', 'bg-surface');
        inactive.classList.remove('bg-header', 'text-white');
        inactive.classList.add('text-muted', 'bg-surface');
    }
}
