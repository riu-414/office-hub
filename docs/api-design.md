# 備品在庫管理システム API 設計書

| 項目 | 内容 |
|---|---|
| バージョン | 1.1 |
| 作成日 | 2026-09-03 |
| 関連文書 | 要件定義書 v1.0 / DB 設計書 v1.0 |

---

## 1. 共通仕様

### 1.1 基本方針

| 項目 | 内容 |
|---|---|
| スタイル | REST |
| ベース URL | `/api/v1` |
| データ形式 | JSON（`Content-Type: application/json`） |
| 文字コード | UTF-8 |
| 日時形式 | ISO 8601（例：`2026-09-03T14:30:00+09:00`） |
| 日付形式 | `YYYY-MM-DD` |
| 命名 | リクエスト・レスポンスともにスネークケース（PHP 側と揃える） |

**バージョニングを URL に含める理由：** ヘッダによるバージョニングより、ブラウザの開発者ツールやログでどのバージョンを叩いたかが一目で分かる。個人〜小規模の運用では可読性を優先する。

### 1.2 認証

**Laravel Sanctum の SPA 認証（Cookie ベース）**を採用する。

```
1. GET  /sanctum/csrf-cookie      → XSRF-TOKEN Cookie を取得
2. POST /api/v1/auth/login        → セッション Cookie が発行される
3. 以降のリクエストは Cookie を自動送信し、X-XSRF-TOKEN ヘッダを付与する
```

| 項目 | 内容 |
|---|---|
| セッション Cookie | `HttpOnly`, `Secure`, `SameSite=Lax` |
| CSRF | 状態変更系（POST/PUT/PATCH/DELETE）に `X-XSRF-TOKEN` ヘッダを必須とする |
| セッション有効期限 | 8 時間（業務時間を想定）。操作があれば延長 |

> **トークン方式（Bearer + localStorage）を選ばなかった理由：** アクセストークンを localStorage に置くと XSS でトークンごと盗まれる。`HttpOnly` Cookie なら JavaScript から読めない。SPA と API を同一ドメインで運用する前提のため、Cookie 方式の制約（クロスドメイン）が問題にならない。

### 1.3 レスポンス形式

**単一リソース**

```json
{
  "data": {
    "id": 12,
    "code": "STA-0012",
    "name": "ボールペン（黒）",
    "type": "consumable",
    "category": { "id": 1, "name": "文具" },
    "warehouse": { "id": 1, "name": "本社3F倉庫" },
    "unit": "本",
    "reorder_point": 20,
    "stock": {
      "on_hand_quantity": 8,
      "lent_quantity": 0,
      "is_low_stock": true
    },
    "version": 3,
    "created_at": "2026-04-01T10:00:00+09:00",
    "updated_at": "2026-09-01T09:12:00+09:00"
  }
}
```

**一覧（ページネーション）**

```json
{
  "data": [ /* ... */ ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 137,
    "last_page": 7
  }
}
```

すべてのレスポンスは API Resource で整形し、Eloquent モデルをそのまま返さない。

### 1.4 エラー形式

```json
{
  "message": "在庫が不足しています。",
  "error_code": "INSUFFICIENT_STOCK",
  "errors": {
    "quantity": ["出庫数が手元在庫（8本）を超えています。"]
  }
}
```

| フィールド | 説明 |
|---|---|
| `message` | 画面にそのまま表示できる日本語のメッセージ |
| `error_code` | フロントが分岐に使う機械可読なコード |
| `errors` | 項目別エラー。フォームの各入力欄に紐付ける |

### 1.5 HTTP ステータスコードの方針

| コード | 用途 |
|---|---|
| 200 | 取得・更新の成功 |
| 201 | 作成の成功 |
| 204 | 削除の成功（本文なし） |
| 401 | 未認証 |
| 403 | 権限不足 |
| 404 | リソースが存在しない |
| 409 | **競合**（楽観ロックの衝突、棚卸しの二重確定） |
| 422 | バリデーションエラー、業務ルール違反（在庫不足など） |
| 429 | レート制限 |
| 500 | サーバエラー |

> **在庫不足を 409 ではなく 422 にした理由：** 409 はリソースの状態が競合しているとき（他者の更新とぶつかった）に使い、422 は「送られた内容がルールを満たさない」ときに使う。在庫不足は入力値の問題としてフォームに表示したいため 422 とし、フロントは `errors.quantity` をそのまま入力欄に出せる。

### 1.6 主なエラーコード

| `error_code` | HTTP | 意味 |
|---|---|---|
| `VALIDATION_FAILED` | 422 | 入力値エラー |
| `INSUFFICIENT_STOCK` | 422 | 手元在庫を超える出庫・貸出 |
| `ITEM_NOT_RETURNABLE` | 422 | 消耗品を貸し出そうとした |
| `EXCESS_RETURN_QUANTITY` | 422 | 貸出数を超える返却 |
| `BACKDATE_OUT_OF_RANGE` | 422 | 7 日を超えるバックデート入力 |
| `STALE_VERSION` | 409 | 楽観ロックの衝突（他ユーザーが先に更新） |
| `ALREADY_CONFIRMED` | 409 | 確定済みの棚卸しを再確定しようとした |
| `ALREADY_REVERSED` | 409 | 取消済みの履歴を再度取り消そうとした |
| `REQUEST_IN_PROGRESS` | 409 | 同じ冪等性キーの処理が進行中 |
| `IDEMPOTENCY_KEY_REUSED` | 422 | 同じ冪等性キーで異なる内容を送信した |
| `FORBIDDEN` | 403 | 権限不足 |

### 1.7 一覧 API の共通パラメータ

| パラメータ | 例 | 説明 |
|---|---|---|
| `page` | `2` | ページ番号（既定 1） |
| `per_page` | `50` | 件数（既定 20、最大 100） |
| `sort` | `-updated_at` | 並べ替え。先頭の `-` で降順 |
| `q` | `ボールペン` | キーワード検索 |
| その他 | `category_id`, `warehouse_id`, `type`, `low_stock` … | エンドポイントごとの絞込 |

### 1.8 在庫変動 API の冪等性

在庫を変動させる POST（入庫・出庫・貸出・返却・取消・棚卸し確定）は、リクエストヘッダ `Idempotency-Key`（UUID）を**必須**とする。

```
Idempotency-Key: 6f8a1c2e-....
```

- 同じキーで再送された場合、処理は行わず**初回と同じレスポンスを返す**
- キーと結果は `idempotency_keys` テーブルに 24 時間保持する（DB 設計書 §2.12）
- 重複の検出は **PRIMARY KEY への INSERT が失敗するかどうか**で行う。「SELECT して無ければ INSERT」では同時リクエストがすり抜ける
- 初回の処理がまだ完了していないうちに再送された場合は 409 `REQUEST_IN_PROGRESS` を返す
- 同じキーで**異なる内容**を送ってきた場合は 422 `IDEMPOTENCY_KEY_REUSED`（リクエストボディのハッシュで判定）
- フロントは送信ボタン押下時に UUID を生成し、リトライ時も同じキーを使う。**送信成功またはフォームを開き直すまでキーを変えない**

> **導入する理由：** 通信の再送やダブルクリックで在庫が二重に引かれる事故は、在庫システムで最も起きやすく、最も影響が大きい。ボタンの二度押し防止（フロント）だけでは、通信タイムアウト後のリトライを防げない。

### 1.9 レート制限

| 対象 | 制限 |
|---|---|
| ログイン | 同一 IP から 5 回 / 分 |
| その他の API | 認証ユーザーあたり 120 回 / 分 |

---

## 2. 権限マトリクス

| 操作 | Admin | Staff | Member |
|---|:---:|:---:|:---:|
| 備品の閲覧・検索 | ○ | ○ | ○ |
| 在庫履歴の閲覧 | ○ | ○ | ○ |
| 備品の登録・編集・削除 | ○ | ○ | × |
| 入庫・出庫の登録 | ○ | ○ | × |
| 在庫履歴の取消 | ○ | ○ | × |
| 貸出・返却の登録 | ○ | ○ | × |
| 自分の貸出状況の閲覧 | ○ | ○ | ○ |
| 棚卸しの実施 | ○ | ○ | × |
| 棚卸しの確定 | ○ | ○ | × |
| カテゴリ・保管場所マスタ | ○ | × | × |
| ユーザー管理 | ○ | × | × |
| 監査ログの閲覧 | ○ | × | × |

権限判定は Laravel Policy に実装し、**すべてのエンドポイントで必ずサーバ側で検証する**。フロントの表示制御は補助でしかない。

---

## 3. エンドポイント一覧

### 3.1 認証

| メソッド | パス | 権限 | 説明 |
|---|---|---|---|
| GET | `/sanctum/csrf-cookie` | 誰でも | CSRF トークン取得 |
| POST | `/api/v1/auth/login` | 誰でも | ログイン |
| POST | `/api/v1/auth/logout` | 認証済 | ログアウト |
| GET | `/api/v1/auth/me` | 認証済 | ログイン中のユーザー情報 |
| PUT | `/api/v1/auth/password` | 認証済 | パスワード変更 |

### 3.2 備品

| メソッド | パス | 権限 | 説明 |
|---|---|---|---|
| GET | `/api/v1/items` | 全員 | 一覧・検索 |
| POST | `/api/v1/items` | Staff+ | 登録 |
| GET | `/api/v1/items/{item}` | 全員 | 詳細 |
| PUT | `/api/v1/items/{item}` | Staff+ | 更新（楽観ロック） |
| DELETE | `/api/v1/items/{item}` | Staff+ | 論理削除 |
| GET | `/api/v1/items/lookup` | 全員 | 備品コードから引く（QR スキャン用） |
| GET | `/api/v1/items/{item}/transactions` | 全員 | 在庫履歴 |
| GET | `/api/v1/items/{item}/loans` | 全員 | 貸出状況 |
| POST | `/api/v1/items/import` | Staff+ | CSV 一括登録 |
| GET | `/api/v1/items/export` | 全員 | CSV 出力 |

### 3.3 在庫

| メソッド | パス | 権限 | 説明 |
|---|---|---|---|
| POST | `/api/v1/items/{item}/receipts` | Staff+ | 入庫登録 |
| POST | `/api/v1/items/{item}/issues` | Staff+ | 出庫（消費）登録 |
| GET | `/api/v1/stock-transactions` | 全員 | 在庫履歴の横断検索 |
| POST | `/api/v1/stock-transactions/{transaction}/reversal` | Staff+ | 取消（逆仕訳） |
| GET | `/api/v1/stock-transactions/export` | 全員 | CSV 出力 |

### 3.4 貸出

| メソッド | パス | 権限 | 説明 |
|---|---|---|---|
| GET | `/api/v1/loans` | Staff+ | 貸出一覧 |
| POST | `/api/v1/loans` | Staff+ | 貸出登録 |
| GET | `/api/v1/loans/{loan}` | Staff+ | 詳細 |
| POST | `/api/v1/loans/{loan}/returns` | Staff+ | 返却登録（一部返却可） |
| GET | `/api/v1/loans/mine` | 全員 | 自分の貸出状況 |

### 3.5 棚卸し

| メソッド | パス | 権限 | 説明 |
|---|---|---|---|
| GET | `/api/v1/stocktakings` | Staff+ | 一覧 |
| POST | `/api/v1/stocktakings` | Staff+ | 開始（理論在庫をスナップショット） |
| GET | `/api/v1/stocktakings/{stocktaking}` | Staff+ | 詳細＋明細 |
| PUT | `/api/v1/stocktakings/{stocktaking}/lines` | Staff+ | 実地数の一括入力 |
| POST | `/api/v1/stocktakings/{stocktaking}/confirm` | Staff+ | 確定（調整トランザクション発行） |
| DELETE | `/api/v1/stocktakings/{stocktaking}` | Staff+ | 中止（下書きのみ） |

### 3.6 マスタ・管理

| メソッド | パス | 権限 | 説明 |
|---|---|---|---|
| GET/POST/PUT/DELETE | `/api/v1/categories` | 参照:全員 / 更新:Admin | カテゴリ |
| GET/POST/PUT/DELETE | `/api/v1/warehouses` | 参照:全員 / 更新:Admin | 保管場所 |
| GET/POST/PUT/DELETE | `/api/v1/users` | Admin | ユーザー |
| GET | `/api/v1/audit-logs` | Admin | 監査ログ |
| GET | `/api/v1/dashboard` | 全員 | ダッシュボード集計 |

---

## 4. 主要エンドポイント詳細

### 4.1 `GET /api/v1/items` — 備品一覧

**クエリパラメータ**

| 名前 | 型 | 説明 |
|---|---|---|
| `q` | string | 品名・備品コードの部分一致 |
| `category_id` | int | カテゴリ絞込 |
| `warehouse_id` | int | 保管場所絞込 |
| `type` | `consumable` \| `returnable` | 種別絞込 |
| `low_stock` | bool | 在庫僅少のみ |
| `sort` | string | `name` / `-updated_at` / `on_hand_quantity` |
| `page` / `per_page` | int | |

**レスポンス 200**

```json
{
  "data": [
    {
      "id": 12,
      "code": "STA-0012",
      "name": "ボールペン（黒）",
      "type": "consumable",
      "category": { "id": 1, "name": "文具" },
      "warehouse": { "id": 1, "name": "本社3F倉庫" },
      "unit": "本",
      "reorder_point": 20,
      "stock": { "on_hand_quantity": 8, "lent_quantity": 0, "is_low_stock": true },
      "image_url": null,
      "version": 3
    }
  ],
  "meta": { "current_page": 1, "per_page": 20, "total": 137, "last_page": 7 }
}
```

> `item_stocks` は常に JOIN して返す。フロントが在庫を取るために N+1 のリクエストを投げる設計にしない。

---

### 4.2 `POST /api/v1/items/{item}/issues` — 出庫登録

**ヘッダ**

```
Idempotency-Key: 6f8a1c2e-3b4d-4e5f-8a9b-0c1d2e3f4a5b
X-XSRF-TOKEN: ...
```

**リクエスト**

```json
{
  "quantity": 3,
  "occurred_at": "2026-09-03T14:30:00+09:00",
  "consumer_id": 42,
  "note": "営業部 会議用"
}
```

| 項目 | 必須 | 検証 |
|---|:---:|---|
| `quantity` | ○ | 1 以上の整数。**手元在庫以下**（サーバ側で排他ロック後に検証） |
| `occurred_at` | ○ | 未来日時は不可。過去は 7 日以内 |
| `consumer_id` | | 存在するユーザー |
| `note` | | 500 文字以内 |

**レスポンス 201**

```json
{
  "data": {
    "id": 8801,
    "item_id": 12,
    "type": "issue",
    "quantity": -3,
    "balance_after": 5,
    "occurred_at": "2026-09-03T14:30:00+09:00",
    "operated_by": { "id": 7, "name": "田中 太郎" },
    "consumer": { "id": 42, "name": "鈴木 花子" },
    "note": "営業部 会議用"
  },
  "stock": { "on_hand_quantity": 5, "lent_quantity": 0, "is_low_stock": true }
}
```

> レスポンスに更新後の在庫を含めることで、フロントは再取得なしに画面を更新できる。

**レスポンス 422（在庫不足）**

```json
{
  "message": "在庫が不足しています。",
  "error_code": "INSUFFICIENT_STOCK",
  "errors": { "quantity": ["出庫数が手元在庫（5本）を超えています。"] }
}
```

---

### 4.3 `POST /api/v1/stock-transactions/{transaction}/reversal` — 取消

**リクエスト**

```json
{ "reason_code": "input_error", "note": "数量を誤って入力したため" }
```

**レスポンス 201** — 取消トランザクションを返す

```json
{
  "data": {
    "id": 8802,
    "type": "issue",
    "quantity": 3,
    "balance_after": 8,
    "reversal_of_id": 8801,
    "reason_code": "input_error"
  },
  "stock": { "on_hand_quantity": 8, "lent_quantity": 0, "is_low_stock": true }
}
```

**エラー**

| 状況 | コード |
|---|---|
| 既に取り消されている | 409 `ALREADY_REVERSED` |
| 取消トランザクション自体を取り消そうとした | 422 `VALIDATION_FAILED` |
| 取り消すと在庫が負になる | 422 `INSUFFICIENT_STOCK` |

> 3 つめは実際に起こり得る。「10 個入庫 → 8 個出庫」の後に入庫を取り消すと在庫が −8 になる。この場合は取消を拒否し、棚卸し調整で対応するよう促す。

---

### 4.4 `POST /api/v1/loans` — 貸出登録

**リクエスト**

```json
{
  "item_id": 55,
  "borrower_id": 42,
  "quantity": 1,
  "due_on": "2026-09-17",
  "note": "客先デモ用"
}
```

| 検証 | 内容 |
|---|---|
| 種別 | 対象備品が `returnable` であること（違反時 422 `ITEM_NOT_RETURNABLE`） |
| 数量 | 手元在庫以下 |
| 返却予定日 | 本日以降 |

**処理**（同一 DB トランザクション内）

```
1. item_stocks を FOR UPDATE でロック
2. 在庫を検証
3. loans を作成
4. stock_transactions(type=lend, quantity=-n, reference=loan) を発行
5. item_stocks.on_hand_quantity -= n, lent_quantity += n
```

---

### 4.5 `POST /api/v1/loans/{loan}/returns` — 返却登録

**リクエスト**

```json
{ "quantity": 1, "returned_at": "2026-09-15T17:00:00+09:00", "note": "" }
```

| 検証 | 内容 |
|---|---|
| 数量 | `quantity <= loan.quantity - loan.returned_quantity`（違反時 422 `EXCESS_RETURN_QUANTITY`） |

**処理**

```
1. item_stocks を FOR UPDATE でロック
2. loans.returned_quantity += n
3. status を再計算（全数返却なら returned、一部なら partially_returned）
4. stock_transactions(type=return, quantity=+n, reference=loan) を発行
5. item_stocks.on_hand_quantity += n, lent_quantity -= n
```

---

### 4.6 `POST /api/v1/stocktakings` — 棚卸し開始

**リクエスト**

```json
{ "title": "2026年上期 棚卸し", "scope_type": "warehouse", "scope_id": 1 }
```

**処理**

対象備品の現在の理論在庫を `stocktaking_lines.expected_quantity` にスナップショットして明細を作成する。備品数が多い場合はキューで非同期に生成し、`status` を `preparing` として返すことも検討する（v1 は同期でよい規模と判断）。

---

### 4.7 `POST /api/v1/stocktakings/{stocktaking}/confirm` — 棚卸し確定

**リクエスト** — 本文なし（`Idempotency-Key` 必須）

**処理**

```
1. UPDATE stocktakings SET status='confirmed' WHERE id=? AND status='draft'
   → 影響行数 0 なら 409 ALREADY_CONFIRMED
2. actual_quantity 入力済み かつ 差異あり の明細それぞれについて
   stock_transactions(type=adjustment, quantity=差異, reference=stocktaking_line) を発行
3. item_stocks を更新（item_id 昇順でロックを取得しデッドロックを回避）
```

**レスポンス 200**

```json
{
  "data": {
    "id": 3,
    "status": "confirmed",
    "confirmed_at": "2026-09-03T18:00:00+09:00",
    "summary": {
      "total_lines": 55,
      "counted_lines": 55,
      "difference_lines": 4,
      "total_shortage": 7,
      "total_overage": 2
    }
  },
  "warnings": [
    {
      "item_id": 12,
      "message": "棚卸し開始後にこの備品の在庫変動がありました。差異に含まれている可能性があります。"
    }
  ]
}
```

> 未入力の明細がある状態での確定は、警告した上で許可する（実地棚卸しでは一部が数えられないことが現実に起きるため）。未入力の明細は調整対象外とする。

---

### 4.8 `PUT /api/v1/items/{item}` — 備品更新（楽観ロック）

**リクエスト** — `version` を必須で送る

```json
{
  "name": "ボールペン（黒・0.5mm）",
  "category_id": 1,
  "warehouse_id": 1,
  "unit": "本",
  "reorder_point": 30,
  "version": 3
}
```

**レスポンス 409（競合）**

```json
{
  "message": "他のユーザーがこの備品を更新しました。最新の内容を確認してから再度保存してください。",
  "error_code": "STALE_VERSION",
  "errors": {},
  "current": { "version": 4, "updated_at": "2026-09-03T15:02:00+09:00", "updated_by": "佐藤 次郎" }
}
```

> `current` に「誰がいつ更新したか」を含めることで、フロントは「佐藤さんが 15:02 に更新しました」と具体的に表示できる。単に「競合しました」と出すより、利用者が次の行動を判断しやすい。

---

### 4.9 `GET /api/v1/dashboard`

```json
{
  "data": {
    "low_stock_count": 6,
    "overdue_loan_count": 2,
    "lending_count": 11,
    "low_stock_items": [ /* 上位5件 */ ],
    "overdue_loans": [ /* 上位5件 */ ],
    "recent_transactions": [ /* 直近10件 */ ]
  }
}
```

> ダッシュボードは複数の集計を返すため、個別 API を並べるとリクエストが 5 本になる。1 エンドポイントに集約する。

---

## 5. バッチ処理（API 外）

| コマンド | 実行時刻 | 内容 |
|---|---|---|
| `php artisan alert:low-stock` | 毎日 08:00 | 在庫僅少品目を抽出し、Admin/Staff へ通知（`notification_logs` で 3 日以内の重複を抑制） |
| `php artisan alert:overdue-loans` | 毎日 08:00 | 返却期限超過を借用者本人と Staff へ通知 |
| `php artisan stock:verify` | 毎日 02:00 | 履歴と集計キャッシュの整合性を検証。乖離があれば Admin へ通知 |

通知は Laravel Queue（database driver）経由で送信し、メール送信の失敗が業務処理をブロックしないようにする。

---

## 6. テスト観点（API レベル）

| # | 観点 | 期待 |
|---|---|---|
| 1 | 手元在庫を超える出庫 | 422 `INSUFFICIENT_STOCK`。在庫は変化しない |
| 2 | **同一備品への同時出庫**（並列 10 リクエスト、在庫 5） | 成功は 5 件まで。在庫が負にならない |
| 3 | 同じ `Idempotency-Key` での再送 | 在庫は 1 回分しか動かない |
| 4 | 棚卸しの二重確定 | 2 回目は 409。調整トランザクションは 1 回分のみ |
| 5 | 取消の二重実行 | 2 回目は 409 `ALREADY_REVERSED` |
| 6 | 楽観ロックの衝突 | 409 `STALE_VERSION`。データは書き換わらない |
| 7 | Member による出庫登録 | 403 |
| 8 | 消耗品の貸出 | 422 `ITEM_NOT_RETURNABLE` |
| 9 | 貸出数を超える返却 | 422 `EXCESS_RETURN_QUANTITY` |
| 10 | 在庫変動後の `stock:verify` | 乖離ゼロ |

**2 が本システムの中核**であり、これが通ることをもって在庫整合性の設計が機能していると見なす。

---

## 7. 改訂履歴

| 版 | 日付 | 内容 |
|---|---|---|
| 1.0 | 2026-09-03 | 初版 |
| 1.1 | 2026-09-03 | 冪等性キーの実装方式を明確化し、関連エラーコードを追加 |
