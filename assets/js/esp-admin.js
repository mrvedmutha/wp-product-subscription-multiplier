document.addEventListener('DOMContentLoaded', function () {

    // 1. Master enable toggle
    var enableToggle = document.getElementById('_esp_enabled');
    var tiersContainer = document.getElementById('esp-tiers-container');

    if (enableToggle && tiersContainer) {
        // Set initial state
        if (enableToggle.checked) {
            tiersContainer.classList.add('esp-active');
        }

        enableToggle.addEventListener('change', function () {
            tiersContainer.classList.toggle('esp-active', this.checked);
        });
    }

    // 2. CMC visibility — hide per-currency sections if CMC not active
    if (tiersContainer && tiersContainer.dataset.cmcActive === '0') {
        document.querySelectorAll('.esp-currency-prices').forEach(function (el) {
            el.style.display = 'none';
        });
    }

    // 3. Tier accordion toggle
    document.querySelectorAll('.esp-tier-header').forEach(function (header) {
        header.addEventListener('click', function () {
            var body = this.parentElement.querySelector('.esp-tier-body');
            var toggle = this.querySelector('.esp-tier-toggle');
            if (body) {
                body.classList.toggle('open');
                if (toggle) {
                    toggle.style.transform = body.classList.contains('open') ? 'rotate(180deg)' : '';
                }
            }
        });
    });

    // 4. Real-time final price computation
    var basePriceInput = document.getElementById('_regular_price');

    function getBasePrice() {
        return parseFloat(basePriceInput ? basePriceInput.value : '0') || 0;
    }

    function computeFinalPrice(n) {
        var base = getBasePrice();
        var mrpOverrideInput = document.getElementById('_esp_' + n + 'm_mrp_override');
        var mrpOverride = mrpOverrideInput ? parseFloat(mrpOverrideInput.value) : NaN;
        var mrp = !isNaN(mrpOverride) && mrpOverride > 0 ? mrpOverride : base * n;

        var discountTypeEl = document.querySelector('input[name="_esp_' + n + 'm_discount_type"]:checked');
        var discountType = discountTypeEl ? discountTypeEl.value : 'percentage';

        var discountValueInput = document.getElementById('_esp_' + n + 'm_discount_value');
        var discountValue = discountValueInput ? parseFloat(discountValueInput.value) : 0;

        var finalPrice = mrp;
        if (discountType === 'percentage' && discountValue > 0) {
            finalPrice = mrp * (1 - discountValue / 100);
        } else if (discountType === 'fixed_total' && discountValue > 0) {
            finalPrice = discountValue;
        }

        var finalSpan = document.getElementById('esp-final-' + n + 'm');
        if (finalSpan) {
            finalSpan.textContent = isNaN(finalPrice) ? '\u2014' : finalPrice.toFixed(2);
        }
    }

    [3, 6, 9, 12].forEach(function (n) {
        computeFinalPrice(n);

        var mrpOverrideInput = document.getElementById('_esp_' + n + 'm_mrp_override');
        var discountValueInput = document.getElementById('_esp_' + n + 'm_discount_value');
        var discountTypeInputs = document.querySelectorAll('input[name="_esp_' + n + 'm_discount_type"]');

        if (mrpOverrideInput) {
            mrpOverrideInput.addEventListener('input', function () { computeFinalPrice(n); });
        }
        if (discountValueInput) {
            discountValueInput.addEventListener('input', function () { computeFinalPrice(n); });
        }
        discountTypeInputs.forEach(function (radio) {
            radio.addEventListener('change', function () { computeFinalPrice(n); });
        });
    });

    if (basePriceInput) {
        basePriceInput.addEventListener('input', function () {
            [3, 6, 9, 12].forEach(function (n) { computeFinalPrice(n); });
        });
    }
});
