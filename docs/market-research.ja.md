# 市場調査と商品選定の記録（2026-11-15）

04 の商品を決めるために行った調査と、その結論です。**都合のよい事実だけを並べていません。** 結論に不利な事実は第4章にまとめています。

## 0. この資料の信頼度（最初にお読みください）

事実を2種類に分けています。

| 表記 | 意味 |
|---|---|
| **【一次】** | 開発環境から一次情報（公式リポジトリの git タグ・ファイル、Packagist、実物の PHP の実行結果）を直接確認したもの |
| **【抜粋】** | 検索結果の抜粋（スニペット）だけに基づくもの。ページそのものは開いていません |

- wordpress.org、php.net、freemius.com、各ホスティング会社のサイトは、この開発環境のネットワーク設定で開けませんでした。迂回はしていません。
- そのため、**インストール数・価格・シェア・日付のうち【抜粋】のものは、すべて要確認**です。販売ページや価格の判断に使う前に、公式ページで確かめてください。
- 第三者の集計サイト同士で数値が食い違うものは、両方を載せています。
- 網羅的な調査ではありません。「見つからなかった」は「存在しない」の証明ではありません。

## 1. 条件と候補

オーナーの条件は3つです。

1. 市場として求められているもの、もしくは諦められてきたもの
2. 人の手では作業工程が多すぎて作れない、もしくはかなり大人数での作業になるもの
3. 無料で完成までできるもの

比較した候補は4つです。

| 記号 | 候補 |
|---|---|
| A | PHP のバージョンアップで壊れる古いプラグイン・テーマを、検査して直すプラグイン |
| B | ページビルダー（Elementor・WPBakery・Divi）の内容を標準のブロックへ変換するプラグイン |
| C | 削除済みプラグインが残したデータ（オプション・テーブル・cron）の持ち主を特定して掃除するプラグイン |
| D | 日本語のサイト内検索（分かち書きと表記ゆれ辞書つき） |

## 2. 結論

**A を採用します。ただし「検査（スキャン）」ではなく「修正」を売り物にし、主市場は日本ではなく海外（英語）に置きます。**

反証役の調査担当の評価は「A が1位。ただし強いからではなく、消去法の僅差」でした。この評価をそのまま受け入れます。確信度は高くありません。

| 候補 | 判定 | 理由 |
|---|---|---|
| A | **採用** | いま期限が重なっている（第3章）。管理画面での自動修正は調査の範囲で見つからなかった。条件3に完全に合う（PHP も検証用の実行環境も無料） |
| B | 不採用 | 主要なビルダー（WPBakery・Divi・Elementor Pro）が有料で、条件3に反する。既存の変換プラグインは有効インストール0〜20台で、売れている証拠がない【抜粋】。見た目の差による返金とサポートが最も重い（推測） |
| C | 不採用 | 既に3社が持ち主の知識ベースを持つ：Advanced Database Cleaner 4.x（クラウド照合、有効10万以上、4.9/5）、Meow Database Cleaner（5,000プラグインを認識）、WP-Optimize（対応表を配布）【抜粋】。堀が先に埋まっている |
| D | 不採用 | 日本語だけで上限が小さい。WordPress 標準の検索は日本語でも部分一致で一応動くため痛みが弱く、需要を示す証拠が見つからなかった【抜粋】 |

## 3. A を支持する事実

### 3.1 既存の標準的な道具が止まっている

| 事実 | 根拠 |
|---|---|
| PHP 互換性チェックの事実上の標準である PHPCompatibility は、安定版が 9.3.5 のまま。PHP 8.x に対応する 10.0.0 は alpha1・alpha2 のタグしかない。開発は続いている（最終コミット 2026-11-05） | 【一次】リポジトリのタグとログ |
| WP Engine の PHP Compatibility Checker は有効インストール約20万、「保守終了」の表示、対象は PHP 8.0 まで、評価 2.7/5（別の集計では 3.8） | 【抜粋】第三者の集計サイト3つ |
| その GitHub リポジトリは 2026-07-20 にアーカイブ | 【抜粋】 |
| 後継を目指す fork（josephfusco/phpcompat）に v2.0.0-beta.1〜beta.5 のタグがある | 【一次】git タグ |

保守が止まり評価も低い道具が、なお約20万サイトで使われている、という点が需要の最も強い証拠です。

### 3.2 期限が重なっている

| 事実 | 根拠 |
|---|---|
| PHP 8.6 は RC7 までタグがあり、正式版のタグはまだない。リリースブランチの NEWS は既に 8.6.1 を指している | 【一次】php-src の git |
| PHP 8.6 の正式版は 2026-11-19 の予定 | 【抜粋】 |
| PHP 8.6 は `spl_object_hash()`、`is_integer()`／`is_long()`／`is_double()`、`doubleval()`、`mb_ereg` 系すべて、`define()` の第3引数などを非推奨にする | 【一次】PHP-8.6 ブランチの UPGRADING |
| PHP 8.2 のセキュリティサポートは 2026-12-31 に終わる。8.1 は 2025-12-31 に終了済み | 【抜粋】二次資料4つ |
| WordPress サイトの PHP の比率（2026-10、WordPress.org 統計の第三者による転載）：7.4 が約16%、8.2 未満の合計が約35%、8.2 が約23〜24%、8.3 が約26〜27% | 【抜粋】転載元によって 0.5〜1 ポイントの差 |
| WordPress.com の基盤（WP Cloud）は PHP 8.2 を 2026-12-15 に削除し、差し戻しはできない。Pressable は 2026-12-08 | 【抜粋】 |
| WP Engine はサポート終了時に全サイトを自動アップグレード。SiteGround も段階的に自動更新 | 【抜粋】 |
| WooCommerce 11.6（2027-02 予定）は PHP 8.1 以上が必須 | 【抜粋】 |
| 2026-10-21 に PHP の緊急リリース（PHP2Shell）。修正版は 8.5.12／8.4.27／8.3.36／8.2.35 で、8.1 以前には正式なリリースがない | 【抜粋】。**版番号は、php-wasm の実物の PHP と git タグで同じ値を確認【一次】。脆弱性の内容は未確認** |

上の比率が正しければ、2027-01-01 の時点でセキュリティサポートが切れた版にいるサイトは約6割になります（35%＋約24%。**【抜粋】の数値からの計算であり、要確認**）。

### 3.3 お金が動いている

| 事実 | 根拠 |
|---|---|
| 手作業の「PHP バージョンアップ対応」の公開料金：国内 8,000円（切替と表示確認のみ）〜120,000円、多くは 30,000〜55,000円。海外 $49〜$399 | 【抜粋】国内6社・海外5社 |
| 古い PHP を使い続けるための有料の延長サポート：GoDaddy（更新時に自動で付く。金額未確認）、one.com（月 5.99 ポンド）、Zone.ee（月 8〜12 ユーロ） | 【抜粋】 |

延長サポートは「コードを直さずに済ませるために毎月払う」支出です。直す道具の価格の目安になります。

### 3.4 修正まで行う道具が見つからない

調査の範囲では、次のものは見つかりませんでした。

- WordPress の管理画面から、ワンクリックで古いコードを自動修正するプラグイン
- 削除された関数（`each()`、`create_function()`、`mysql_*` など）の互換層を配る、現役の WordPress.org プラグイン
- 日本製・日本語圏向けの同種の道具

近いものは次のとおりです。

| 名称 | 内容 | 根拠 |
|---|---|---|
| qiyuanlabs/refit | コマンドラインの修正ツール。v0.1.1 が 2026-10-31 公開。**動かすのに PHP 8.1 以上が必要**（古い PHP のサイト上では動かない）。Rector と PHPCompatibility の alpha に依存 | 【一次】Packagist |
| InfinitumForm/php-legacy-compat | 削除された関数の互換層（GitHub の公開ソフトウェア、v1.0.0）。WordPress.org への掲載は確認できず | 【一次】タグの存在／【抜粋】内容 |
| Instant Programming「WP Rescue Repair」 | 有料サービス。「検証済みの修正の適用とカナリアによる自動ロールバック」と記載。価格不明 | 【抜粋】 |
| KAGG Compatibility | 警告の表示を抑えるだけ。修正はしない | 【抜粋】 |

## 4. A に不利な事実

### 4.1 検査（スキャン）だけの道具が急増している

2026-09-30〜11-05 の約6週間に、新しい静的スキャナが少なくとも5本、WordPress.org に公開されました（Airworthy、Leaplens、Bright Labs、CatCode、Hexloom）。ほかに Bumpcheck、CompatNav、BrightLeaf Digital、Eli's、Plugin Compatibility Checker（約8,000〜9,000）、Upgrade Readiness Monitor など、合計10本以上あります。大半は有効インストールが10未満です。【抜粋】

- 価格は低い：Bright Labs は Pro 月 $9／サイト、Agency 月 $49（25サイト）を「予定価格」として記載。CompatShield のポータルは月 $1〜2。【抜粋】
- 多くは PHP_CodeSniffer と PHPCompatibility の alpha を同梱する作りです。【抜粋】

**帰結**：検査だけでは条件2（人手では作れない）を満たしません。6週間で10人が作れたからです。検査は無料の入口にはなりますが、売り物にも堀にもなりません。

### 4.2 ホスティング会社が古い PHP を残している

- 国内9社（エックスサーバー、さくら、ロリポップ、ConoHa WING、mixhost、heteml、お名前.com、カゴヤ、KUSANAGI）で、2025〜2026年の旧版の提供終了・強制移行の告知は見つかりませんでした。【抜粋】
- 2026-10 の緊急対応では、エックスサーバーが PHP 5.1〜8.1 を版を変えずにパッチ適用、ロリポップが 5.3〜8.5 に修正適用、お名前.com が 7.4 にもパッチ適用。海外でも Hostinger（5.2〜8.5）、OVHcloud（7.4〜8.5）、cPanel（7.4／8.0／8.1）が旧版を維持。Kinsta は 7.4／8.0 を追加料金なしで継続提供。【抜粋】

反証役の調査担当は、**「主要ホスト、特に国内大手が旧 PHP をパッチ付きで維持し、強制切替が例外的」と確認できた場合は A を3位に落とす**、としていました。国内については、この条件にほぼ当てはまる事実が出ています。海外は 3.2 のとおり強制する会社と残す会社が分かれています。

**帰結**：日本では「強制されるから直す」という動機は弱い。主市場を海外に置き、日本では「警告が画面に出る」「脆弱性が怖い」を動機として扱います。

### 4.3 対象の層が縮んでいる

- PHP 7.4 の比率は 42.80%（2024-04）→ 32.83%（2024-11）→ 約16%（2026-10）と、2年半で半分以下になりました。【抜粋】
- 致命的なエラーになるのは主に 7.4 → 8.x の移行で、8.2 → 8.4 以降は警告が中心です（推測。ただし 8.0 での `count(null)` の TypeError などは【一次】で確認）。
- 残っている 7.4 のサイトは放置されているものが多く、最も払わない層である可能性があります（推測）。

### 4.4 削除された関数の困りごとは、新しい証拠が弱い

フォーラムやブログで 2025〜2026年に新しく出ている例は、PHP 8.2〜8.4 由来の非推奨警告と型エラー（`count()`、動的プロパティ、暗黙の nullable）でした。**`each()`／`mysql_connect()` は 2020〜2023年の資料しか見つかりませんでした。**【抜粋】

**帰結**：削除された関数の互換層は優先度を下げます。中心に置くのは、8.2〜8.6 の非推奨と型エラーの自動修正です。

### 4.5 そのほか

- 買い方が「壊れた → 直した → 解約」の単発になりやすい（推測）。年額の更新は、複数サイトを保守する制作会社のライセンスが頼りです。
- 他人のコードを書き換えるため、「自動修正で壊れた」という問い合わせの負担が重い（推測）。更新による上書き、改ざん検知との衝突もあり得ます。
- 開発者は AI の助手で古いコードを直せます。直した実例の記事が複数あります。【抜粋】
- プラグイン各社の新規販売が 17.8% 減ったという調査の引用があります。【抜粋】出典は確定できていません。
- WordPress.org の審査の手引きに「他のテーマ・プラグインの編集を要求するな」とあります。【抜粋】明示的な禁止条文は見つかっていません。書き換えの機能は Pro（Freemius で配布、審査の対象外）にだけ入れます。

## 5. この結論を変える事実

次のどれかが確認できたら、選定をやり直すべきです。

| 事実 | 影響 |
|---|---|
| 海外の主要ホストも、旧 PHP を無料で維持する方針に揃う | A の緊急性が失われる |
| 管理画面で自動修正まで行うプラグインが、既に相当数の利用者を得ている | A の空白がなくなる |
| WordPress.org の公式統計で、PHP 8.2 以下の比率が【抜粋】の値より大幅に低い | 対象の規模が小さい |

## 6. 確認できなかったこと

- wordpress.org の各ページの実物（インストール数、最終更新日、評価、価格）
- wordpress.org/about/stats の公式の表。2026-10 の値はすべて第三者の転載
- php.net の公式のサポート期限の表、PHP 8.6.0 最終版の非推奨一覧（RC7 時点の UPGRADING は確認済み）、PHP 9.0 の日程
- 国内各社の旧版の提供終了スケジュール（「見つからなかった」のであって、「存在しない」とは確認できていない）
- 新しいスキャナの大半のインストール数と、有料版の実際の売れ行き
- Freemius の現在の手数料（03 の資料の値も要確認のまま）

一次ページで確かめるには、wordpress.org、make.wordpress.org、www.php.net をこの開発環境のネットワーク設定で許可する必要があります。

## 7. 主な出典

すべて【抜粋】として参照したものです。

- 競合：wordpress.org/plugins/php-compatibility-checker/、github.com/wpengine/phpcompat、github.com/josephfusco/phpcompat、wordpress.org/plugins/brightlabs-compatibility-scanner/、wordpress.org/plugins/hexloom-php-version-scan/、ja.wordpress.org/plugins/bumpcheck/、wordpress.org/plugins/leaplens/、wordpress.org/plugins/airworthy/、wordpress.org/plugins/plugin-compatibility-checker/、wordpress.org/plugins/kagg-compatibility/、packagist.org/packages/qiyuanlabs/refit、github.com/InfinitumForm/php-legacy-compat
- 需要：make.wordpress.org/core/2024/04/08/dropping-support-for-php-7-1/、make.wordpress.org/core/2026/01/09/dropping-support-for-php-7-2-and-7-3/、make.wordpress.org/core/2026/05/22/php-support-clarification-2026/、wp.cloud/preparing-for-the-removal-of-php-8-2/、pressable.com/knowledgebase/php-update-faqs/、developer.woocommerce.com/2026/09/29/php-8-1-requirement/、www.godaddy.com/help/php-extended-support-42401、www.one.com/en-us/hosting/php-extended-support/、kinsta.com/changelog/end-of-life-php-support/
- 国内のホスティング：www.xserver.ne.jp/news_detail.php?view_id=19756、www.sakura.ad.jp/corporate/information/announcements/2026/10/22/1968226117/、www.onamae.com/news/article/11596
- 料金：www.wp-119.com/wordpress-php-update、sd-webworks.com/services/wordpress-update/、www.scitech.co.jp/php8-update、b-create.co.jp/solutions/bcreatelab/wordpress-php-update/、webrepair.jp/lp/php8/、www.nerdpress.net/shop/php-update-advanced/
- 代替案：sigmaplugin.com/downloads/wordpress-advanced-database-cleaner/、meowapps.com/products/database-cleaner-pro/、www.gravitykit.com/wordpress-page-builder-market-share-2026/、relevanssi.com/knowledge-base/relevanssi-and-languages

【一次】として確認したもの：github.com/php/php-src（タグ、PHP-8.0〜PHP-8.6 と master の UPGRADING、NEWS）、github.com/PHPCompatibility/PHPCompatibility（タグ、develop）、github.com/nikic/PHP-Parser（v5.9.0）、repo.packagist.org/p2/qiyuanlabs/refit.json、npm の @php-wasm/node 3.1.64（PHP 7.4.33／8.0.30／8.1.34／8.2.35／8.3.36／8.4.27／8.5.12 を実行）。
