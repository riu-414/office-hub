# 共通 DB 設計書

| 項目 | 内容 |
|---|---|
| バージョン | 1.2 |
| 作成日 | 2026-09-06 |
| 対象 DBMS | MySQL 8.0（InnoDB） |
| 関連文書 | `docs/architecture.md` / `docs/common/requirements.md` |

**本書 §1 の方針は全システムのテーブルに適用される。** 各システムの DB 設計書はこれを前提とし、重複して書かない。

---

## 1. 全体方針

### 1.1 文字コード・照合順序

| 項目 | 設定 | 理由 |
|---|---|---|
| 文字セット | `utf8mb4` | 絵文字・機種依存文字を安全に扱うため |
| 照合順序 | `utf8mb4_ja_0900_as_cs` | 日本語の並び順を正しく扱う。**大文字小文字を区別する**（備品コード `AB-01` と `ab-01` を別物として扱いたいため） |
| ストレージエンジン | InnoDB | トランザクションと行ロックが必須 |

> 照合順序が大文字小文字を区別するため、**メールアドレスは保存前に小文字へ正規化する**必要がある。しないと `User@example.com` と `user@example.com` が別ユーザーとして登録できてしまう。

### 1.2 命名規約

| 対象 | 規約 | 例 |
|---|---|---|
| テーブル名 | スネークケース・複数形 | `stock_transactions` |
| カラム名 | スネークケース・単数形 | `on_hand_quantity` |
| 主キー | `id`（`BIGINT UNSIGNED AUTO_INCREMENT`） | |
| 外部キー | `<単数形テーブル名>_id` | `item_id` |
| 真偽値 | `is_` プレフィックス | `is_active` |
| 日時 | `_at` サフィックス | `confirmed_at` |
| 日付のみ | `_on` サフィックス | `due_on` |
| インデックス | `idx_<テーブル>_<カラム列>` | `idx_stock_tx_item_occurred` |
| ユニーク制約 | `uq_<テーブル>_<カラム列>` | `uq_items_code` |
| 外部キー制約 | Laravel の既定 `<テーブル>_<カラム>_foreign` に従う | `items_category_id_foreign` |

> **インデックスとユニーク制約は名前を明示的に指定し、外部キーは Laravel の既定に任せる。** 索引名は「あとで落とす」ときに必ず必要になるため、規約に沿った名前を自分で付ける。一方、外部キーは `dropForeign(['column_name'])` と列名で指定でき、既定の命名を前提に Laravel が名前を解決してくれるため、あえて逆らわない。

### 1.3 共通カラム

| カラム | 型 | 適用範囲 | 備考 |
|---|---|---|---|
| `created_at` / `updated_at` | `TIMESTAMP NULL` | 全テーブル | Laravel の `timestamps()` |
| `deleted_at` | `TIMESTAMP NULL` | マスタ系のみ | 論理削除。**トランザクション系には付けない** |

**論理削除を使う：** `users`, `departments`, `systems`, `categories`, `warehouses`, `items`
**論理削除を使わない：** `system_user_roles`, `stock_transactions`, `loans`, `stocktakings`, `stocktaking_lines`, `audit_logs`, `idempotency_keys`

履歴系テーブルを論理削除できると、「削除されていない行だけを集計する」という条件が全ての計算に混入し、整合性検証も複雑になる。**履歴は消さないという方針をスキーマの段階で強制する。**

### 1.4 論理削除とユニーク制約

**MySQL の UNIQUE 制約は NULL を互いに異なる値として扱う。** そのため `UNIQUE (email, deleted_at)` としても、`deleted_at` が NULL の行同士では制約が働かず、同じメールアドレスを何行でも登録できてしまう。

論理削除を行うテーブルのユニーク項目には、**生成列（Generated Column）を使う**。

```sql
email             VARCHAR(255) NOT NULL,
deleted_at        TIMESTAMP NULL,
email_unique_key  VARCHAR(255) GENERATED ALWAYS AS
                  (IF(deleted_at IS NULL, email, NULL)) STORED,
UNIQUE KEY uq_users_email (email_unique_key)
```

- 有効な行（`deleted_at IS NULL`）は `email` がそのまま入るので、**重複が正しく拒否される**
- 論理削除された行は NULL になり、NULL 同士は重複とみなされないため、**同じ値で再登録できる**

Laravel のマイグレーションでの書き方。

```php
$table->string('email_unique_key')->nullable()
      ->storedAs('if(deleted_at is null, email, null)');
$table->unique('email_unique_key', 'uq_users_email');
```

> **注意：** 生成列は参照先の列が既に存在している必要がある。既存テーブルへ `deleted_at` と生成列を同時に追加すると1本の `ALTER TABLE` にまとめられて失敗するため、`Schema::table()` を2回に分ける。
>
> **なぜアプリ側の検証だけで済ませないか：** 「登録前に同名が存在するか SELECT する」方式は、同時に届いた2リクエストが両方とも「存在しない」と判定してすり抜ける。一意性は DB の制約で担保し、アプリ側の検証は分かりやすいエラーを出すための補助と位置づける。

### 1.5 金額・数量の型

| 対象 | 型 | 理由 |
|---|---|---|
| 数量 | `INT`（符号付き）／在庫キャッシュは `INT UNSIGNED` | 履歴は出庫を負で表すため符号が必要。キャッシュ側は非負を DB で保証する |
| 単価・金額 | `DECIMAL(12, 2)` | 浮動小数点は金額に使わない |

---

## 2. 共通テーブル

### 2.1 `users` — 利用者

| カラム | 型 | NULL | デフォルト | 説明 |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NO | AUTO | |
| `name` | VARCHAR(255) | NO | | 氏名。Laravel 標準の `users` テーブルの定義をそのまま使う |
| `email` | VARCHAR(255) | NO | | ログイン ID。**小文字に正規化して保存** |
| `email_verified_at` | TIMESTAMP | YES | NULL | |
| `password` | VARCHAR(255) | NO | | bcrypt ハッシュ |
| `is_system_admin` | BOOLEAN | NO | false | **全体管理者フラグ** |
| `department_id` | BIGINT UNSIGNED | YES | NULL | 所属部署 |
| `is_active` | BOOLEAN | NO | true | 無効化フラグ。false ならログイン不可 |
| `remember_token` | VARCHAR(100) | YES | NULL | |
| `created_at` / `updated_at` / `deleted_at` | TIMESTAMP | YES | NULL | |

**制約・索引**

- `email_unique_key` 生成列に `uq_users_email` UNIQUE（§1.4）
- `fk_users_department_id` FK → `departments`(`id`) ON DELETE SET NULL

> **`role` 列を持たない点が重要。** システム内での役割は `system_user_roles` に持つ（§2.4）。`users` に単一のロールを置くと「在庫では管理者、勤怠では一般」を表現できない。
>
> `is_system_admin` は基盤の管理権限のみを表し、**各システムの操作権限は含まない**（共通要件定義書 §7-2）。

---

### 2.2 `departments` — 部署

| カラム | 型 | NULL | デフォルト | 説明 |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NO | AUTO | |
| `name` | VARCHAR(100) | NO | | 部署名 |
| `created_at` / `updated_at` / `deleted_at` | TIMESTAMP | YES | NULL | |

- `name_unique_key` 生成列に `uq_departments_name` UNIQUE（§1.4）

---

### 2.3 `systems` — システムマスタ

ポータルに並ぶ業務システムの定義。

| カラム | 型 | NULL | デフォルト | 説明 |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NO | AUTO | |
| `key` | VARCHAR(50) | NO | | システムキー。URL に使う。例：`inventory` |
| `name` | VARCHAR(100) | NO | | 表示名。例：備品在庫管理 |
| `description` | VARCHAR(255) | YES | NULL | ポータルのカードに出す説明文 |
| `icon` | VARCHAR(50) | YES | NULL | アイコン識別子 |
| `display_order` | INT | NO | 0 | ポータルでの並び順 |
| `is_active` | BOOLEAN | NO | true | false ならポータルに表示しない |
| `created_at` / `updated_at` / `deleted_at` | TIMESTAMP | YES | NULL | |

**制約・索引**

- `key_unique_key` 生成列に `uq_systems_key` UNIQUE（§1.4）

> **レコードはシーダで投入する。**システムの追加は実装を伴うため、画面から新規作成はできない（共通要件定義書 §7-5）。
>
> `key` を持つのは、URL とコード上の識別子を DB の連番 ID に依存させないため。`/inventory/items` というパスが `systems.id = 1` に紐づいていると、環境ごとに ID がずれた瞬間に壊れる。

---

### 2.4 `system_user_roles` — システム利用権限

**どのユーザーが、どのシステムで、どの役割を持つか。**

| カラム | 型 | NULL | デフォルト | 説明 |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NO | AUTO | |
| `user_id` | BIGINT UNSIGNED | NO | | |
| `system_id` | BIGINT UNSIGNED | NO | | |
| `role` | VARCHAR(50) | NO | | システム内での役割。例：`admin` / `staff` / `member` |
| `granted_by` | BIGINT UNSIGNED | YES | NULL | 権限を付与した全体管理者 |
| `created_at` / `updated_at` | TIMESTAMP | YES | NULL | |

**制約・索引**

- `uq_system_user_roles` UNIQUE (`user_id`, `system_id`) — 1ユーザーが1システムに持つロールは1つ
- FK `user_id` → `users`(`id`) ON DELETE CASCADE
- FK `system_id` → `systems`(`id`) ON DELETE RESTRICT
- FK `granted_by` → `users`(`id`) ON DELETE SET NULL

> **`user_id` 単独のインデックスは張らない。** 複合ユニーク (`user_id`, `system_id`) の左端が `user_id` であるため、`WHERE user_id = ?`（ポータル表示で使う）はこの索引で処理できる。単独索引を追加しても重複するだけで、書き込みコストと容量を無駄に増やす。

> **この行の有無がアクセス権そのもの。** 行が無ければそのシステムを使えず、ポータルにも表示されない（共通要件定義書 §7-3）。
>
> **`role` を ENUM にしない理由：** ロール名の集合はシステムごとに異なる。ENUM にすると、新しいシステムを追加するたびにこのテーブルの `ALTER` が必要になり、共通基盤が個別システムの都合に引きずられる。有効な値の検証は各システムの実装が担う。
>
> **`ON DELETE CASCADE`（user 側）：** ユーザーを物理削除する場面は無い（論理削除で運用する）が、テスト用のデータ整理で効く。system 側を `RESTRICT` にしているのは、利用者が残っているシステムを誤って消せないようにするため。

---

### 2.5 `audit_logs` — 監査ログ

全システム共通の変更履歴。

| カラム | 型 | NULL | デフォルト | 説明 |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NO | AUTO | |
| `user_id` | BIGINT UNSIGNED | YES | NULL | 操作者。バッチ実行時は NULL |
| `action` | VARCHAR(50) | NO | | `created` / `updated` / `deleted` |
| `auditable_type` | VARCHAR(100) | NO | | モデルのクラス名 |
| `auditable_id` | BIGINT UNSIGNED | NO | | |
| `old_values` | JSON | YES | NULL | 変更前 |
| `new_values` | JSON | YES | NULL | 変更後 |
| `ip_address` | VARCHAR(45) | YES | NULL | IPv6 対応で 45 桁 |
| `user_agent` | VARCHAR(255) | YES | NULL | |
| `created_at` | TIMESTAMP | YES | NULL | |

**制約・索引**

- `idx_audit_logs_auditable` INDEX (`auditable_type`, `auditable_id`)
- `idx_audit_logs_user_created` INDEX (`user_id`, `created_at`)

> Eloquent の Observer で自動記録する。`password` と `remember_token` は記録対象から除外する。

---

### 2.6 `idempotency_keys` — 冪等性キー

状態を変える API の二重実行を防ぐ（共通 API 設計書 §1.8）。

| カラム | 型 | NULL | デフォルト | 説明 |
|---|---|---|---|---|
| `key` | CHAR(36) | NO | | **主キー。**クライアントが生成する UUID |
| `user_id` | BIGINT UNSIGNED | NO | | |
| `endpoint` | VARCHAR(255) | NO | | メソッド＋パス |
| `request_hash` | CHAR(64) | NO | | リクエストボディの SHA-256 |
| `status_code` | SMALLINT UNSIGNED | YES | NULL | 初回のレスポンスコード |
| `response_body` | JSON | YES | NULL | 初回のレスポンス本文 |
| `created_at` | TIMESTAMP | NO | | |

**制約・索引**

- PRIMARY KEY (`key`)
- `idx_idempotency_created` INDEX (`created_at`) — 24 時間経過分の削除用

> **処理の流れ**
>
> 1. `key` を PRIMARY KEY として INSERT を試みる（`status_code` は NULL のまま）
> 2. **重複エラーになった場合＝すでに同じキーで処理が始まっている**
>    - `status_code` が入っていれば、保存済みのレスポンスをそのまま返す
>    - まだ NULL なら「処理中」として 409 を返す
> 3. INSERT に成功したら本処理を実行し、結果を書き戻す
> 4. `request_hash` が初回と異なる場合は 422（同じキーで違う内容を送るのは誤用）
>
> **重複検出を DB の PRIMARY KEY に任せている点が要点。**「SELECT して無ければ INSERT」だと、同時に届いた2リクエストが両方ともすり抜ける。
>
> 古いキーは `php artisan idempotency:prune`（日次）で 24 時間経過分を削除する。

---

## 3. 初期データ（シード）

| テーブル | 内容 |
|---|---|
| `departments` | 総務部、営業部、開発部 |
| `systems` | `inventory`（備品在庫管理） |
| `users` | 全体管理者 1 名、在庫管理者 1 名、在庫担当 1 名、一般 1 名 |
| `system_user_roles` | 上記ユーザーに `inventory` の各ロールを付与。**一般ユーザーの1名は権限を付与せず**、「利用できるシステムがありません」の表示を確認できるようにする |

デモ時に権限による表示の違いが確認できるようシードを設計する。

---

## 4. 改訂履歴

| 版 | 日付 | 内容 |
|---|---|---|
| 1.2 | 2026-09-11 | `users.name` の長さを実装（Laravel 標準の 255）に合わせた |
| 1.1 | 2026-09-10 | `system_user_roles` の冗長な単独インデックスを削除。外部キーの命名規約を Laravel の既定に合わせた |
| 1.0 | 2026-09-06 | 初版。備品在庫管理の DB 設計書から共通部分を分離し、`systems` / `system_user_roles` を追加 |
