{{-- Renumera las filas nuevas de estados (add_state[N][...]) para que el
     formulario legacy mande el mismo objeto por estado que la ui/v2. El
     clonado de app.js (.empty-input-add-new) copia la fila con su índice, así
     que sin esto todas irían como add_state[0]. Se engancha una sola vez. --}}
<script>
    (function () {
        if (window.__tpStateRowsBound) return;
        window.__tpStateRowsBound = true;

        function renumber(container) {
            $(container).find('.form-group[data-new-state]').each(function (i) {
                $(this).find('[name]').each(function () {
                    this.name = this.name.replace(/add_state\[[^\]]*\]/, 'add_state[' + i + ']');
                });
            });
        }

        // focusin llega después del clonado (ese manejador está ligado antes).
        $(document).on('focusin', '.empty-input-items', function () {
            renumber(this);
        });
        $(document).on('click', '.empty-input-items .delete-item', function () {
            var c = $(this).closest('.empty-input-items');
            setTimeout(function () { renumber(c); }, 0);
        });
    })();
</script>
