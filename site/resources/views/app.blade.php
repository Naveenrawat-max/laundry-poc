{{-- The one page of the web app. React takes over inside #app; every path that is not the API lands here. --}}
<!doctype html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Proti mat tracking</title>
    {{-- Sets the theme before the first paint, so a light-mode user never sees a dark flash. --}}
    <script>(function(){try{var t=localStorage.getItem('chaiui-theme');if(t!=='light'&&t!=='dark')t='dark';document.documentElement.classList.toggle('dark',t==='dark');document.documentElement.style.colorScheme=t}catch(e){}})()</script>
    {{-- Path of APP_URL, for example "/proti". Empty when the app owns the host root. The app reads this before it calls the API. --}}
    <script>window.__APP_BASE__ = @json(rtrim((string) (parse_url((string) config('app.url'), PHP_URL_PATH) ?: ''), '/'));</script>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
