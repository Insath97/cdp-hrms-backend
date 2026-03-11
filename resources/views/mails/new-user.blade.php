<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Welcome to {{ config('app.name') }}</title>
</head>

<body>
    <p>Hello {{ $user['name'] }},</p>

    <p>Your account has been created successfully. Below are your login credentials:</p>

    <p>
        <strong>Username:</strong> {{ $user['username'] }}<br>
        <strong>Email:</strong> {{ $user['email'] }}<br>
        <strong>Password:</strong> {{ $password }}
    </p>

    <p><strong>Account Type:</strong> {{ ucfirst($user['user_type']) }}</p>

    @if(isset($role))
        <p><strong>Role:</strong> {{ $role }}</p>
    @endif

    <p>You can login here: <a href="{{ $login_url }}">{{ $login_url }}</a></p>

    <p>Regards,<br>
        {{ $created_by }}<br>
        {{ config('app.name') }} Team</p>
</body>

</html>