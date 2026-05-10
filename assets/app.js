import '@hotwired/turbo';
import * as Turbo from '@hotwired/turbo';
import './bootstrap.js';
import './styles/app.css';

Turbo.config.forms.confirm = (message) =>
    new Promise((resolve) =>
        document.dispatchEvent(new CustomEvent('turbo:confirm', { detail: { message, resolve } }))
    );
