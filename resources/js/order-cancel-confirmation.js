import Swal from 'sweetalert2';

document.addEventListener('DOMContentLoaded', () => {
    const forms = document.querySelectorAll('[data-order-cancel-form]');

    forms.forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const result = await Swal.fire({
                title: 'Cancel this order?',
                text: 'This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Cancel order',
                cancelButtonText: 'Keep order',
                reverseButtons: true,
                focusCancel: true,
            });

            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
});
