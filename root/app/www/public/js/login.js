$(function () {
    let isSubmitting = false;

    function runLogin() {
        if (isSubmitting) {
            return;
        }

        const username = $('#loginUsername').val().trim();
        const password = $('#loginPassword').val();

        if (username == '' || password == '') {
            toast(translate('login'), translate('usernameAndPasswordRequired'), 'error');
            return;
        }

        isSubmitting = true;
        $('#loginSubmitBtn').prop('disabled', true);

        let payload = '&username=' + encodeURIComponent(username);
        payload += '&password=' + encodeURIComponent(password);

        $.ajax({
            url: BASE_URL + 'ajax/login.php',
            type: 'post',
            dataType: 'json',
            data: payload,
            success: function (response) {
                if (!response || response.error) {
                    toast(translate('login'), (response && response.message) || translate('invalidUsernameOrPassword'), 'error');
                    return;
                }

                window.location.href = 'index.php';
            },
            error: function (xhr) {
                toast(translate('login'), (xhr && xhr.responseJSON && xhr.responseJSON.message) || translate('unableToLogin'), 'error');
            },
            complete: function () {
                isSubmitting = false;
                $('#loginSubmitBtn').prop('disabled', false);
            }
        });
    }

    window.runLogin = runLogin;

    $('#loginUsername, #loginPassword').on('keydown', function (event) {
        if (event.key == 'Enter') {
            event.preventDefault();
            runLogin();
        }
    });
});
