jQuery(document).ready(function () {
    if (jQuery('input[type=radio][name=payment_method]:checked').attr('id') == 'payment_method_ei_stripe') {
        if (document.getElementById('ei_stripe-card-number') != null) {
            setTimeout(stripe_add_required, 1000);
        }
    } else {
        stripe_remove_required();
    }
    jQuery('input[name="payment_method"]').change(function () {
        var checked = jQuery('input[type=radio][name=payment_method]:checked').attr('id');
        if (checked == 'payment_method_ei_stripe') {
            if (jQuery("#payment_method_ei_stripe").is(':checked')) {
                setTimeout(stripe_add_required, 1000);
            }
        } else {
            stripe_remove_required();
        }
    });
    jQuery('input[name="payment_method"]').live('change', function () {
        if (this.id == 'payment_method_ei_stripe') {
            if (jQuery("#payment_method_ei_stripe").is(':checked')) {
                setTimeout(stripe_add_required, 1000);
            }
        } else {
            stripe_remove_required();
        }
    });
});

function stripe_add_required() {
    jQuery('#ei_stripe-card-number').prop('required', true);
    jQuery('#ei_stripe-card-expiry').prop('required', true);
    jQuery('#ei_stripe-card-cvc').prop('required', true);
    return true;
}

function stripe_remove_required() {
    jQuery('#ei_stripe-card-number').prop('required', false);
    jQuery('#ei_stripe-card-expiry').prop('required', false);
    jQuery('#ei_stripe-card-cvc').prop('required', false);
    jQuery('.cc-stripe').css('box-shadow', 'none');
    return true;
}
