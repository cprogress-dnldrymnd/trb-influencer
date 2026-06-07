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

        window.ddConfirm(CONFIRM_MESSAGE, function (closePopup) {
            button.dataset[CONFIRMED_FLAG] = 'true';

            var settled = false;
            var observer;
            var fallbackTimer;

            var finish = function () {
                if (settled) {
                    return;
                }
                settled = true;
                if (observer) {
                    observer.disconnect();
                }
                clearTimeout(fallbackTimer);
                closePopup();
            };

            // myCred swaps the buy button out for the unlocked content (or an
            // error message) once the AJAX purchase completes — watching for
            // it leaving the DOM is the most reliable "we're done" signal we
            // have without hooking into myCred's own AJAX internals.
            observer = new MutationObserver(function () {
                if (!document.body.contains(button)) {
                    finish();
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });

            // Safety net in case myCred leaves the button in place (e.g. a
            // failed purchase shows an inline error rather than swapping it out).
            fallbackTimer = setTimeout(finish, 15000);

            button.click();
        }, { keepOpen: true, processingText: 'Processing…' });
    }, true);
})();
