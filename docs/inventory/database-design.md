# 備品在庫管理システム DB 設計書

| 項目 | 内容 |
|---|---|
| バージョン | 2.0 |
| 作成日 | 2026-09-03 |
| 対象 DBMS | MySQL 8.0（InnoDB） |
| 関連文書 | `docs/common/database-design.md` / `docs/inventory/requirements.md` |

---

## 1. 本書の範囲

備品在庫管理システム固有のテーブルを定義する。

**全テーブルに共通する方針は `docs/common/database-design.md` §1 に定める。** 文字コード・照合順序、命名規約、共通カラム、論理削除とユニーク制約（生成列方式）、金額・数量の型は本書では繰り返さない。

共通テーブル（`users` / `departments` / `systems` / `system_user_roles` / `audit_logs` / `idempotency_keys`）も同文書で定義する。

---

## 2. テーブル定義

本書で定義するテーブルは以下。番号は §2.3 から始まる（§2.1 `users` / §2.2 `departments` は共通側へ移動したため欠番）。

| テーブル | 役割 |
|---|---|
| `categories` | 備品カテゴリ |
| `warehouses` | 保管場所（将来の拠点） |
| `items` | 備品マスタ |
| `item_stocks` | 現在庫の集計キャッシュ |
| `stock_transactions` | 在庫変動の履歴。**在庫の唯一の正** |
| `loans` | 貸出 |
| `stocktakings` / `stocktaking_lines` | 棚卸しヘッダ / 明細 |
| `notification_logs` | 通知の重複送信抑制用 |

### 2.3 `categories` — 備品カテゴリ

| カラム | 型 | NULL | デフォルト | 説明 |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NO | AUTO | |
| `name` | VARCHAR(100) | NO | | 「文具」「PC 周辺機器」など |
| `display_order` | INT | NO | 0 | 表示順 |
| `created_at` / `updated_at` / `deleted_at` | TIMESTAMP | YES | NULL | |

- `name_unique_key` 生成列に `uq_categories_name` UNIQUE（共通DB設計書 §1.4）

---

### 2.4 `warehouses` — 保管場所

| カラム | 型 | NULL | デフォルト | 説明 |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NO | AUTO | |
| `name` | VARCHAR(100) | NO | | 「本社 3F 倉庫」など |
| `created_at` / `updated_at` / `deleted_at` | TIMESTAMP | YES | NULL | |

- `name_unique_key` 生成列に `uq_warehouses_name` UNIQUE（共通DB設計書 §1.4）

> v1 では備品ごとに 1 か所を紐付ける（要件定義 §12-4）。将来の拠点別在庫に備えて独立テーブルとして切っておく。

---

### 2.5 `items` — 備品マスタ

| カラム | 型 | NULL | デフォルト | 説明 |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NO | AUTO | |
| `code` | VARCHAR(50) | NO | | 備品コード。QR/バーコードの値 |
| `name` | VARCHAR(200) | NO | | 品名 |
| `description` | TEXT | YES | NULL | 説明 |
| `type` | ENUM('consumable','returnable') | NO | | 消耗品 / 貸出品 |
| `category_id` | BIGINT UNSIGNED | NO | | |
| `warehouse_id` | BIGINT UNSIGNED | NO | | |
| `unit` | VARCHAR(20) | NO | '個' | 個 / 箱 / 本 |
| `reorder_point` | INT UNSIGNED | NO | 0 | 発注点。**0 はアラート対象外**（在庫 0 でも僅少と判定しない） |
| `image_path` | VARCHAR(255) | YES | NULL | |
| `is_active` | BOOLEAN | NO | true | 取り扱い停止フラグ |
| `version` | INT UNSIGNED | NO | 1 | **楽観ロック用**（要件定義 §10.2） |
| `created_at` / `updated_at` / `deleted_at` | TIMESTAMP | YES | NULL | |

**制約・索引**

- `code_unique_key` 生成列に `uq_items_code` UNIQUE（共通DB設計書 §1.4）
- `idx_items_name` INDEX (`name`) — キーワード検索用
- `idx_items_category_warehouse` INDEX (`category_id`, `warehouse_id`) — 絞込用
- `fk_items_category_id` FK → `categories`(`id`) ON DELETE RESTRICT
- `fk_items_warehouse_id` FK → `warehouses`(`id`) ON DELETE RESTRICT

> **`ON DELETE RESTRICT` の理由：** 備品が紐付いたままカテゴリを消せてしまうと、一覧の絞込が壊れる。マスタは論理削除で運用し、参照がある間は物理削除を DB レベルで拒否する。

---

### 2.6 `item_stocks` — 在庫集計キャッシュ

| カラム | 型 | NULL | デフォルト | 説明 |
|---|---|---|---|---|
| `item_id` | BIGINT UNSIGNED | NO | | **主キー**（`items` と 1:1） |
| `on_hand_quantity` | INT UNSIGNED | NO | 0 | 手元在庫。貸出中は含まない |
| `lent_quantity` | INT UNSIGNED | NO | 0 | 貸出中数 |
| `is_low_stock` | BOOLEAN | NO | false | 在庫僅少フラグ。`reorder_point > 0 AND on_hand_quantity <= reorder_point` |
| `last_transaction_id` | BIGINT UNSIGNED | YES | NULL | 最後に反映したトランザクション |
| `updated_at` | TIMESTAMP | YES | NULL | |

**制約・索引**

- PRIMARY KEY (`item_id`)
- `fk_item_stocks_item_id` FK → `items`(`id`) ON DELETE CASCADE
- `idx_item_stocks_low_stock` INDEX (`is_low_stock`) — 在庫僅少の抽出用
- CHECK (`on_hand_quantity` >= 0) / CHECK (`lent_quantity` >= 0)

> **`is_low_stock` を非正規化で持つ理由：** 在庫僅少の判定は `on_hand_quantity <= items.reorder_point` という**列同士の比較**になり、索引が効かない。ダッシュボードの表示と日次アラートの両方で毎回全件走査するのは性能要件（一覧 500ms 以内）に反する。在庫更新時と発注点変更時に、同一 DB トランザクション内でフラグを再計算する。`stock:verify` の検証対象に含めて乖離を検出する。

> **このテーブルの位置づけ（最重要）**
>
> 在庫の唯一の正は `stock_transactions` の累計であり、本テーブルは**参照性能のための集計キャッシュ**にすぎない。
>
> - 在庫変動時は、`stock_transactions` への INSERT と本テーブルの UPDATE を**同一 DB トランザクション内**で行う
> - 更新前に該当行を `SELECT ... FOR UPDATE` でロックする。**在庫の同時更新制御はこの 1 行のロックに集約される**
> - `UNSIGNED` と CHECK 制約により、アプリケーションのバグで在庫が負になった場合は DB がエラーを返す（**最後の砦を DB に置く**）
> - `php artisan stock:verify` で累計との乖離を検出し、日次で実行する
>
> 主キーを `item_id` 単独にしているのは v1 の仕様（要件定義 §12-4）による。拠点別在庫に移行する際は (`item_id`, `warehouse_id`) の複合主キーへ変更する。

---

### 2.7 `stock_transactions` — 在庫トランザクション

**このシステムにおける在庫の唯一の正。** 更新・削除は行わない。

| カラム | 型 | NULL | デフォルト | 説明 |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NO | AUTO | |
| `item_id` | BIGINT UNSIGNED | NO | | |
| `type` | ENUM('receipt','issue','lend','return','adjustment') | NO | | 入庫 / 出庫 / 貸出 / 返却 / 棚卸調整 |
| `quantity` | INT | NO | | **符号付き。**入庫・返却は正、出庫・貸出は負。調整は両方あり得る |
| `balance_after` | INT UNSIGNED | NO | | この変動を反映した後の手元在庫 |
| `unit_price` | DECIMAL(12,2) | YES | NULL | 入庫時の単価 |
| `supplier` | VARCHAR(200) | YES | NULL | 入庫時の仕入先 |
| `operated_by` | BIGINT UNSIGNED | NO | | 操作したユーザー |
| `consumer_id` | BIGINT UNSIGNED | YES | NULL | 出庫時の使用者 |
| `reference_type` | VARCHAR(50) | YES | NULL | 'loan' / 'stocktaking_line' |
| `reference_id` | BIGINT UNSIGNED | YES | NULL | 上記の ID |
| `reversal_of_id` | BIGINT UNSIGNED | YES | NULL | **取消元トランザクション**（§3.2） |
| `reason_code` | VARCHAR(50) | YES | NULL | 調整・取消の理由コード |
| `note` | TEXT | YES | NULL | |
| `occurred_at` | DATETIME | NO | | 業務上の発生日時（バックデート入力を許容） |
| `created_at` | TIMESTAMP | YES | NULL | システムへの記録日時 |

**制約・索引**

- `idx_stock_tx_item_occurred` INDEX (`item_id`, `occurred_at`, `id`) — 備品詳細の履歴表示
- `idx_stock_tx_occurred` INDEX (`occurred_at`) — 期間検索
- `idx_stock_tx_reference` INDEX (`reference_type`, `reference_id`) — 貸出からの履歴逆引き
- `uq_stock_tx_reversal` UNIQUE (`reversal_of_id`) — **1 件の取消は 1 回まで**（二重取消の防止を DB で保証）
- `fk_stock_tx_item_id` FK → `items`(`id`) ON DELETE RESTRICT
- `fk_stock_tx_operated_by` FK → `users`(`id`) ON DELETE RESTRICT
- CHECK (`quantity` <> 0)

> **`occurred_at` と `created_at` を分ける理由：** 「昨日の入庫を今日登録した」というケースは実務で日常的に起きる。業務上の時系列（`occurred_at`）と記録の時系列（`created_at`）を混同すると、月次集計がズレる。
>
> **`balance_after` を持つ理由：** 履歴一覧を見るだけで残数の推移を追える。また `stock:verify` で集計キャッシュとの突合に使える。ただしバックデート入力があると過去分の `balance_after` は再計算が必要になるため、**バックデートは当日を含む過去 7 日以内に制限**し、それ以前の訂正は取消トランザクションで行う運用とする。

---

### 2.8 `loans` — 貸出

| カラム | 型 | NULL | デフォルト | 説明 |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NO | AUTO | |
| `item_id` | BIGINT UNSIGNED | NO | | |
| `borrower_id` | BIGINT UNSIGNED | NO | | 借用者 |
| `quantity` | INT UNSIGNED | NO | | 貸出数 |
| `returned_quantity` | INT UNSIGNED | NO | 0 | 返却済み数 |
| `due_on` | DATE | NO | | 返却予定日 |
| `lent_at` | DATETIME | NO | | |
| `returned_at` | DATETIME | YES | NULL | 全数返却された日時 |
| `status` | ENUM('lending','partially_returned','returned') | NO | 'lending' | |
| `lent_by` | BIGINT UNSIGNED | NO | | 貸出処理をした担当者 |
| `note` | TEXT | YES | NULL | |
| `created_at` / `updated_at` | TIMESTAMP | YES | NULL | |

**制約・索引**

- `idx_loans_status_due` INDEX (`status`, `due_on`) — 期限超過の抽出（日次バッチ）
- `idx_loans_borrower` INDEX (`borrower_id`, `status`) — マイ貸出状況
- `idx_loans_item` INDEX (`item_id`, `status`) — 備品詳細の貸出状況
- CHECK (`returned_quantity` <= `quantity`)

> `status` は `quantity` と `returned_quantity` から導出できるが、**期限超過の抽出クエリで索引を効かせるために冗長に保持する**。整合性はモデル側で一元的に更新し、`stock:verify` の検証対象に含める。

---

### 2.9 `stocktakings` — 棚卸しヘッダ

| カラム | 型 | NULL | デフォルト | 説明 |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NO | AUTO | |
| `title` | VARCHAR(200) | NO | | 「2026 年上期 棚卸し」 |
| `scope_type` | ENUM('all','category','warehouse') | NO | | 対象範囲 |
| `scope_id` | BIGINT UNSIGNED | YES | NULL | カテゴリ ID / 保管場所 ID |
| `status` | ENUM('draft','confirmed') | NO | 'draft' | |
| `created_by` | BIGINT UNSIGNED | NO | | |
| `confirmed_by` | BIGINT UNSIGNED | YES | NULL | |
| `confirmed_at` | DATETIME | YES | NULL | |
| `created_at` / `updated_at` | TIMESTAMP | YES | NULL | |

---

### 2.10 `stocktaking_lines` — 棚卸し明細

| カラム | 型 | NULL | デフォルト | 説明 |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NO | AUTO | |
| `stocktaking_id` | BIGINT UNSIGNED | NO | | |
| `item_id` | BIGINT UNSIGNED | NO | | |
| `expected_quantity` | INT UNSIGNED | NO | | **開始時にスナップショットした理論在庫** |
| `actual_quantity` | INT UNSIGNED | YES | NULL | 実地在庫。未入力は NULL |
| `reason_code` | VARCHAR(50) | YES | NULL | 差異理由（破損 / 紛失 / 記録漏れ / その他） |
| `note` | TEXT | YES | NULL | |
| `counted_by` | BIGINT UNSIGNED | YES | NULL | |
| `counted_at` | DATETIME | YES | NULL | |
| `created_at` / `updated_at` | TIMESTAMP | YES | NULL | |

**制約・索引**

- `uq_stocktaking_lines` UNIQUE (`stocktaking_id`, `item_id`) — 同一棚卸し内で同じ備品が重複しない
- `fk_stocktaking_lines_stocktaking_id` FK → `stocktakings`(`id`) ON DELETE CASCADE

> **`expected_quantity` をスナップショットする理由：** 棚卸し中も通常の入出庫は発生し得る。確定時に「今の在庫」と比較すると、棚卸し中の正当な変動まで差異として計上されてしまう。開始時点の理論在庫を固定し、差異 = `actual_quantity` − `expected_quantity` として扱う。
>
> なお、棚卸し中に在庫変動があった場合、確定時に「開始後に変動があった備品」を検出して警告する（実装課題として認識しておく）。

---

### 2.11 `notification_logs` — 通知履歴

| カラム | 型 | NULL | デフォルト | 説明 |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NO | AUTO | |
| `type` | VARCHAR(50) | NO | | 'low_stock' / 'overdue_loan' |
| `notifiable_type` | VARCHAR(50) | NO | | 'item' / 'loan' |
| `notifiable_id` | BIGINT UNSIGNED | NO | | |
| `sent_at` | DATETIME | NO | | |

- `idx_notification_logs` INDEX (`type`, `notifiable_type`, `notifiable_id`, `sent_at`)

> 同じ備品の在庫僅少を毎日通知すると通知が無視されるようになる。「直近 N 日以内に同じ対象へ同じ種別を送っていればスキップ」する判定に使う。

---

> **監査ログと冪等性キーは共通テーブル。** `docs/common/database-design.md` §2.5 / §2.6 に定義する。在庫の各操作も同じ仕組みを利用する。

---

## 3. 中核ロジックの実装仕様

### 3.1 在庫変動の処理手順

すべての在庫変動（入庫・出庫・貸出・返却・調整）は、以下の手順を共通のサービスクラスに集約する。

```
DB::transaction(function () {
    // 1. 在庫行を排他ロック
    //    item_stocks の行は items の作成時に必ず同時に作られている前提とする
    //    （ここで firstOrCreate すると、同時実行時に重複 INSERT が起きうる）
    $stock = ItemStock::where('item_id', $itemId)->lockForUpdate()->firstOrFail();

    // 2. 変動後の在庫を計算
    $after = $stock->on_hand_quantity + $quantity;   // $quantity は符号付き

    // 3. 検証：在庫を負にする変動は拒否
    if ($after < 0) {
        throw new InsufficientStockException();
    }

    // 4. 履歴を記録
    $tx = StockTransaction::create([... 'balance_after' => $after]);

    // 5. 集計キャッシュを更新（在庫僅少フラグも同時に再計算）
    $stock->update([
        'on_hand_quantity'    => $after,
        'is_low_stock'        => $item->reorder_point > 0 && $after <= $item->reorder_point,
        'last_transaction_id' => $tx->id,
    ]);
});
```

**設計上のポイント**

| 論点 | 判断 |
|---|---|
| ロックの対象 | `item_stocks` の 1 行のみ。`items` はロックしない（マスタ編集と在庫更新を無関係にするため） |
| ロックの粒度 | 備品単位。異なる備品の在庫操作は並行して実行できる |
| デッドロック対策 | 複数備品を一括操作する場合は **`item_id` の昇順でロックを取得**し、ロック順序を固定する |
| 最後の砦 | `on_hand_quantity` の `UNSIGNED` と CHECK 制約。手順 3 の検証を通り抜けるバグがあっても DB が拒否する |
| 在庫行の生成 | **備品の作成と同一トランザクションで `item_stocks` の行を作る。**在庫変動時に `firstOrCreate` すると、初回の同時アクセスで重複 INSERT が発生し、`item_id` の PRIMARY KEY 違反または在庫の二重計上を招く |

### 3.2 誤登録の取消（逆仕訳）

```
元:   id=100  type=issue      quantity=-5   balance_after=15
取消: id=101  type=issue      quantity=+5   balance_after=20  reversal_of_id=100
```

- 取消トランザクションは元と同じ `type` を持ち、`quantity` の符号を反転させる
- `reversal_of_id` に UNIQUE 制約があるため、同じ履歴を二重に取り消せない
- 取消トランザクション自体は取り消せない（`reversal_of_id` が既に埋まっている行を対象にできない）
- 一覧では元と取消をペアで打ち消し線表示し、「何が起きたか」を隠さない

### 3.3 棚卸し確定の冪等化

```sql
UPDATE stocktakings
   SET status = 'confirmed', confirmed_at = NOW(), confirmed_by = ?
 WHERE id = ? AND status = 'draft';
```

影響行数が 0 なら既に確定済みとして扱い、エラーではなく「確定済み」として応答する。ダブルクリックや通信リトライで調整トランザクションが二重発行されるのを防ぐ。

差異調整は、この UPDATE が成功した同一トランザクション内で、`actual_quantity` が入力済みかつ差異のある明細に対してのみ発行する。

### 3.4 整合性検証コマンド

```bash
php artisan stock:verify [--fix]
```

| 検証項目 | 内容 |
|---|---|
| 在庫キャッシュの整合性 | `SUM(stock_transactions.quantity)` と `item_stocks.on_hand_quantity` の一致 |
| 貸出中数の整合性 | 未返却 `loans` の `quantity - returned_quantity` の合計と `item_stocks.lent_quantity` の一致 |
| 残高推移の連続性 | `balance_after` が前レコードの `balance_after + quantity` と一致するか |
| 貸出ステータスの整合性 | `status` が `quantity` / `returned_quantity` と矛盾していないか |
| 在庫僅少フラグの整合性 | `is_low_stock` が `reorder_point > 0 AND on_hand_quantity <= reorder_point` と一致するか |

乖離を検出したら管理者へ通知する。`--fix` は履歴を正としてキャッシュを再構築する（履歴側は絶対に書き換えない）。

---

## 4. 主要クエリと索引の対応

| 画面 / 処理 | クエリの概要 | 使用する索引 |
|---|---|---|
| 備品一覧（在庫僅少で絞込） | `items` JOIN `item_stocks` WHERE `is_low_stock` = true | `idx_item_stocks_low_stock` |
| 備品一覧（カテゴリ絞込＋キーワード） | `items` WHERE `category_id` = ? AND `name` LIKE '%?%' | `idx_items_category_warehouse` で絞ってから LIKE |
| 備品詳細の履歴 | `stock_transactions` WHERE `item_id` = ? ORDER BY `occurred_at` DESC | `idx_stock_tx_item_occurred` |
| 期限超過の抽出（日次） | `loans` WHERE `status` <> 'returned' AND `due_on` < CURDATE() | `idx_loans_status_due` |
| マイ貸出状況 | `loans` WHERE `borrower_id` = ? AND `status` <> 'returned' | `idx_loans_borrower` |
| 在庫履歴の期間検索 | `stock_transactions` WHERE `occurred_at` BETWEEN ? AND ? | `idx_stock_tx_occurred` |

---

## 5. 認識している課題

| # | 課題 | 対応方針 |
|---|---|---|
| 1 | `is_low_stock` の非正規化により、`items.reorder_point` を変更した際にフラグの再計算漏れが起きうる | 発注点の更新は在庫サービス経由に限定し、同一トランザクション内でフラグを再計算する。`stock:verify` でも検証する（§2.6 で対応済み） |
| 2 | キーワード検索が `LIKE '%...%'` で前方一致索引が使えない | 備品 10,000 件なら実用範囲。件数が増えたら MySQL の全文索引（ngram パーサ）へ移行する |
| 3 | バックデート入力時に過去の `balance_after` が実態とズレる | 入力可能なバックデート範囲を 7 日以内に制限し、それ以前は取消トランザクションで訂正する運用とする |
| 4 | 棚卸し中の在庫変動 | 確定時に「開始後に変動があった備品」を検出して警告表示する |

---

## 6. 初期データ（シード）

ユーザー・部署・システム・利用権限のシードは共通側（共通DB設計書 §3）で投入する。本書は在庫データのみを扱う。

| テーブル | 内容 |
|---|---|
| `categories` | 文具、事務用品、PC 周辺機器、AV 機器、防災用品 |
| `warehouses` | 本社 3F 倉庫、本社 1F 受付、営業所 |
| `items` | 消耗品 40 件、貸出品 15 件 |
| `stock_transactions` | 過去 6 か月分の入出庫をランダム生成（グラフとダッシュボードが意味を持つ量） |
| `loans` | 貸出中 5 件（うち期限超過 2 件） |

デモ時に「在庫僅少」「返却期限超過」「棚卸し差異」が**必ず画面に現れる**ようにシードを設計する。機能があっても空の画面では伝わらないため。

---

## 7. 改訂履歴

| 版 | 日付 | 内容 |
|---|---|---|
| 1.0 | 2026-09-03 | 初版 |
| 2.0 | 2026-09-06 | 複数システム構成へ移行。共通方針・`users`・`departments`・`audit_logs`・`idempotency_keys` を `docs/common/database-design.md` へ分離 |
| 1.2 | 2026-09-03 | 論理削除テーブルのユニーク制約を生成列方式に変更。メールアドレスの小文字正規化を追記 |
| 1.1 | 2026-09-03 | `is_low_stock` の判定式を修正（`reorder_point > 0` を条件に追加）、`item_stocks` 行の生成タイミングを明記、`idempotency_keys` テーブルを追加 |
