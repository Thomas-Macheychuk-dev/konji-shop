import Swal from 'sweetalert2';

document.addEventListener('DOMContentLoaded', () => {
    const forms = document.querySelectorAll('[data-order-cancel-form]');

    forms.forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const result = await Swal.fire({
                title: 'Anulować to zamówienie?',
                text: 'Tej akcji nie można cofnąć.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Anuluj zamówienie',
                cancelButtonText: 'Zachowaj zamówienie',
                reverseButtons: true,
                focusCancel: true,
            });

            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
});
