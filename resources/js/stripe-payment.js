// Stripe Payment Element for the order page. The order is marked paid by the webhook,
// never by the redirect back — this only collects the card and confirms the intent.
export default (clientSecret, publishableKey, returnUrl) => ({
    stripe: null,
    elements: null,
    busy: false,
    error: '',

    mount() {
        if (typeof Stripe === 'undefined') {
            this.error = 'Stripe.js failed to load.';

            return;
        }

        this.stripe = Stripe(publishableKey);
        this.elements = this.stripe.elements({ clientSecret });
        this.elements.create('payment').mount(this.$refs.element);
    },

    async submit() {
        if (! this.stripe || this.busy) return;

        this.busy = true;
        this.error = '';

        const { error } = await this.stripe.confirmPayment({
            elements: this.elements,
            confirmParams: { return_url: returnUrl },
        });

        // Only reached on failure: on success Stripe redirects to return_url.
        this.error = error?.message ?? 'Payment failed.';
        this.busy = false;
    },
});
