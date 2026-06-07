/**
 * Confirmation gate for the myCred "Sell Content" buy button.
 *
 * myCred processes the credit spend immediately on click. To give the user a
 * chance to back out, this intercepts the click in the capture phase (so it
 * runs before myCred's own handler), shows the theme's ddConfirm() dialog,
 * and only lets the original click through — re-dispatching it — once the
 * user explicitly confirms the spend.
 */
(function () {
    var BUY_BUTTON_SELECTOR = '.mycred-buy-this-content-button';
    var CONFIRMED_FLAG = 'ddBuyConfirmed';
    var CONFIRM_MESSAGE = "You're about to spend 1 credit to unlock this creator's contact information. Credits are non-refundable once spent — would you like to continue?";

    document.addEventListener('click', function (e) {
        var button = e.target.closest && e.target.closest(BUY_BUTTON_SELECTOR);
        if (!button) {
            return;
        }

        // This click is the one we re-dispatched after the user confirmed — let it through.
        if (button.dataset[CONFIRMED_FLAG] === 'true') {
            delete button.dataset[CONFIRMED_FLAG];
            return;
        }

        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        window.ddConfirm(CONFIRM_MESSAGE, function () {
            button.dataset[CONFIRMED_FLAG] = 'true';
            button.click();
        });
    }, true);
})();
