{{--
    The typed half of Section 7's guard rail.

    The hold is the shop system's, and comes with its stylesheet. The typing is
    the panel's own, because only the panel asks for it — "hold to confirm, and
    type the shop's name" is Section 7's rule for suspending and for creating.

    Compared case-insensitively and trimmed: the point is that somebody read
    which shop they are about to stop, not that they can use a shift key.
--}}
<script>
    document.querySelectorAll('input[data-confirm-word]').forEach((input) => {
        const button = document.getElementById(input.dataset.confirmTarget);
        if (! button) return;

        const tidy = (value) => value.trim().toLowerCase();
        const wanted = tidy(input.dataset.confirmWord);

        const check = () => {
            const matches = tidy(input.value) === wanted;
            button.disabled = ! matches;
            input.classList.toggle('is-valid', matches && input.value !== '');
            input.classList.toggle('is-invalid', ! matches && input.value !== '');
        };

        input.addEventListener('input', check);
        check();
    });
</script>
