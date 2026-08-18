<!DOCTYPE html>
<html lang="tr>
<head>
    <meta charset="UTF-8">
     <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Todo App</title>
<style>
    * {
            box-sizing: border-box;
        }


        body {

            margin: 0;

            font-family: Arial, sans-serif;

            min-height: 100vh;

            display: flex;

            justify-content: center;

            align-items: center;

            background: linear-gradient(
                135deg,
                #111827,
                #312e81,
                #4f46e5
            );

            color: white;
        }


        .welcome {

            width: 90%;

            max-width: 700px;

            text-align: center;

            padding: 60px 40px;

            background: rgba(255,255,255,0.10);

            border: 1px solid rgba(255,255,255,0.15);

            border-radius: 25px;

            backdrop-filter: blur(12px);

            box-shadow:
                0 20px 50px rgba(0,0,0,0.25);
        }


        .logo {

            font-size: 60px;

            margin-bottom: 15px;
        }


        h1 {

            font-size: 48px;

            margin: 0 0 15px 0;
        }


        .subtitle {

            font-size: 19px;

            color: #d1d5db;

            margin-bottom: 35px;

            line-height: 1.6;
        }


        .login-button {

            display: inline-block;

            padding: 14px 35px;

            background: white;

            color: #312e81;

            text-decoration: none;

            border-radius: 10px;

            font-size: 17px;

            font-weight: bold;

            transition: 0.3s;
        }


        .login-button:hover {

            transform: translateY(-3px);

            box-shadow:
                0 10px 25px rgba(0,0,0,0.25);
        }


        .footer {

            margin-top: 35px;

            font-size: 13px;

            color: #c7d2fe;
        }

    </style>

</head>


<body>


<div class="welcome">


    <div class="logo">
        📝
    </div>


    <h1>
        Todo App
    </h1>


    <div class="subtitle">

        Görevlerini oluştur,
        takip et ve tamamla.

        <br>

        İşlerini daha düzenli yönet.

    </div>


    <a
        href="login.php"
        class="login-button"
    >
        Giriş Yap →
    </a>


    <div class="footer">

        Görev yönetimini kolaylaştır.

    </div>


</div>


</body>

</html>

