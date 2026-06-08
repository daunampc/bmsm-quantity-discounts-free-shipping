document.addEventListener('DOMContentLoaded', function() {
    var productCartForm = document.querySelector('form.cart');
    var offerBox = document.querySelector('.single-product .bmsm-qdfs-offers');

    if (productCartForm && offerBox) {
        productCartForm.insertAdjacentElement('afterend', offerBox);
    }
});

document.addEventListener('click', function(event) {
    var copyButton = event.target.closest('.bmsm-qdfs-copy');
    if (copyButton) {
        var code = copyButton.getAttribute('data-bmsm-code') || '';
        if (!code) {
            return;
        }
        navigator.clipboard.writeText(code).then(function() {
            var original = copyButton.innerHTML;
            copyButton.classList.add('is-copied');
            copyButton.textContent = 'Copied';
            window.setTimeout(function() {
                copyButton.classList.remove('is-copied');
                copyButton.innerHTML = original;
            }, 1500);
        }).catch(function() {
            window.prompt('Copy coupon code:', code);
        });
        return;
    }

    var buyButton = event.target.closest('.bmsm-qdfs-buy');
    if (!buyButton) {
        return;
    }

    var qty = buyButton.getAttribute('data-bmsm-qty');
    var quantityInput = document.querySelector('form.cart input.qty');
    if (quantityInput && qty) {
        quantityInput.value = qty;
        quantityInput.dispatchEvent(new Event('change', { bubbles: true }));
        quantityInput.focus();
    }
});
