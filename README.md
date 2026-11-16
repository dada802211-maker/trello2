# Trello2

React + TypeScript のフロントエンドと PHP セッション API で動作する Trello 風ボードです。DB は MySQL の `trello2` を使用します。旧 `trello-clone-api` (Node.js) は本構成では起動しません。

## ローカル (Laragon)

必要なもの: Laragon の Apache、MySQL、PHP 8.1 以降 (PDO MySQL 有効)、Node.js 18 以降。

1. Laragon で Apache と MySQL を起動します。
2. phpMyAdmin 等で `trello2` データベースを作成し、`php/api/schema.sql` を実行してテーブルを作成します。
3. Laragon の MySQL ユーザー/パスワードに合わせて `php/api/config.php` を設定します。既定値は `127.0.0.1:3306`、DB `trello2`、ユーザー `root`、パスワードなしです。
4. ターミナルを2つ開き、フロントとAPIを起動します。

```powershell
cd C:\laragon\www\trello2\front
npm install
npm run dev
```

```powershell
cd C:\laragon\www\trello2
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' -S 127.0.0.1:8888 -t php php/router.php
```

フロントは Vite の `http://localhost:5173` で開きます。`/api` はPHPへプロキシされ、PHPセッションCookieを使用します。PHPの場所が異なる場合はコマンドのバージョンフォルダをインストール済みのものに読み替えてください。

## レンタルサーバーへの配置

`release/2026-11-16/` の中身をドメインの公開ディレクトリへアップロードします。`index.html` と `assets/` が公開ルートに置かれ、`php/` フォルダも同じ階層に必要です。Apache の `mod_rewrite` と `.htaccess` が有効な環境を想定しています。PHP 8.1 以降と PDO MySQL が必要です。

1. サーバーで MySQL データベース `trello2` と専用ユーザーを作り、そのユーザーに権限を付与します。
2. `php/api/config.php` の接続設定をサーバーの値に変更します (または `TRELLO_DB_HOST`, `TRELLO_DB_PORT`, `TRELLO_DB_NAME`, `TRELLO_DB_USER`, `TRELLO_DB_PASSWORD` 環境変数を設定)。
3. `php/api/schema.sql` を使ってテーブルを作成します。DB自体は事前に作成してください。
4. 公開ディレクトリにアップロードし、HTTPSを有効にします。セッションCookieは HttpOnly / SameSite=Lax で、HTTPSでは Secure になります。

DB設定ファイルにはパスワードを置くため、公開リポジトリへ本番資格情報をコミットしないでください。必要なら `config.php` を公開ルート外へ移し、接続コードのパスを変更してください。

## API

- `POST /api/auth/signup`, `POST /api/auth/signin`, `POST /api/auth/signout`, `GET /api/auth/me`
- `PUT /api/account/profile`
- `GET|POST|PUT|DELETE /api/lists`
- `GET|POST|PUT|DELETE /api/cards`

更新・削除リクエストはログインユーザーが参加しているボード内に限定されます。
