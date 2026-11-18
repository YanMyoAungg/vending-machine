(() => {
    "use strict";

    const toMills = (amount) => {
        const [whole, fraction = ""] = String(amount).split(".");
        return Number.parseInt(whole, 10) * 1000 + Number.parseInt((fraction + "000").slice(0, 3), 10);
    };

    const formatMoney = (mills) => {
        const whole = Math.floor(mills / 1000);
        let fraction = String(mills % 1000).padStart(3, "0");

        while (fraction.length > 2 && fraction.endsWith("0")) {
            fraction = fraction.slice(0, -1);
        }

        return `USD ${whole}.${fraction}`;
    };

    document.querySelectorAll("form[data-confirm]").forEach((form) => {
        form.addEventListener("submit", (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    const quantityInput = document.querySelector("[data-quantity-input]");
    const quantityTotal = document.querySelector("[data-quantity-total]");

    if (quantityInput && quantityTotal) {
        const unitPrice = quantityInput.dataset.unitPrice;

        const updateTotal = () => {
            const quantity = Number.parseInt(quantityInput.value, 10);

            if (!Number.isFinite(quantity) || quantity < 1) {
                quantityTotal.textContent = "—";
                return;
            }

            quantityTotal.textContent = formatMoney(toMills(unitPrice) * quantity);
        };

        quantityInput.addEventListener("input", updateTotal);
        updateTotal();
    }

    const validateField = (field) => {
        const rules = (field.dataset.rules || "").split("|").filter(Boolean);
        const value = field.value.trim();
        let message = "";

        for (const rule of rules) {
            const [name, parameter] = rule.split(":");

            if (name === "required" && value === "") {
                message = "This field is required.";
            } else if (name === "min" && value !== "" && value.length < Number(parameter)) {
                message = `Use at least ${parameter} characters.`;
            } else if (name === "max" && value.length > Number(parameter)) {
                message = `Use at most ${parameter} characters.`;
            } else if (name === "email" && value !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                message = "Enter a valid email address.";
            } else if (name === "integer" && value !== "" && !/^\d+$/.test(value)) {
                message = "Enter a whole number.";
            } else if (name === "decimal" && value !== "" && !/^\d+(\.\d{1,3})?$/.test(value)) {
                message = "Enter a price with at most 3 decimals.";
            }

            if (message !== "") {
                break;
            }
        }

        field.setCustomValidity(message);

        return message === "";
    };

    document.querySelectorAll("form[data-validate]").forEach((form) => {
        const fields = Array.from(form.querySelectorAll("[data-rules]"));

        fields.forEach((field) => {
            field.addEventListener("input", () => field.setCustomValidity(""));
        });

        form.addEventListener("submit", (event) => {
            const valid = fields.every((field) => validateField(field));

            if (!valid) {
                event.preventDefault();
                const firstInvalid = fields.find((field) => field.validationMessage !== "");
                firstInvalid?.reportValidity();
            }
        });
    });
})();
