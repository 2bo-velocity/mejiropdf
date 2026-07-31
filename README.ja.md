# MejiroPDF

PHP 8.3+ のための、モダンでストリーム指向、かつメモリ効率に優れた PDF 生成エンジン。

> English: [README.md](./README.md)

> ステータス: **初期実装**（第1期の基盤）。本パッケージは `mejiropdf-dev` モノレポの
> 内部にあり、公開リポジトリは `mejiropdf` です。

## 動作要件

- PHP **8.3 以上**（typed class constants、`readonly` クラス、enum を使用）
- ext-mbstring
- `psr/log`（インターフェースのみ — エンジンはロギングフレームワークを同梱しません）
- 64bit

## インストール（公開後）

```bash
composer require 2bo-velocity/mejiropdf
```

## クイックスタート

```php
use MejiroPDF\Document;
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Page\Orientation;
use MejiroPDF\Page\PageSize;

$pdf = new Document();                              // 既定は A4 縦

$pdf->addPage()
    ->text(20, 30, 'Hello, MejiroPDF', size: 28.0, color: '#06B6D4')
    ->line(20, 36, 190, 36, weight: 0.4)
    ->rect(20, 44, 60, 24, stroke: '#333333', fill: '#F0F9FF');

$pdf->addPage(PageSize::a4(Orientation::LANDSCAPE))
    ->text(20, 25, 'Second page', font: StandardFont::TIMES_ROMAN, size: 18.0);

$pdf->save('hello.pdf');                            // または: $bytes = $pdf->output();
```

座標と長さは**左上を原点とするミリメートル**（X は右方向、Y は下方向）、
フォントサイズは**ポイント**です。

[`examples/hello.php`](examples/hello.php) を参照してください。

## 設計

- **O(1) メモリ**: ページは出力バッファへ直接ストリームされ、エンジンは文書全体の
  DOM を保持しません。`SafeStreamWriter` が xref テーブル用に正確なバイトオフセットを
  追跡します。
- **Fail-Fast**: すべてのエラーは安定したコード（`MP-[コンポーネント]-[4桁]`、例:
  `MP-SIO-3001`）と診断用のコンテキスト配列を持ちます。
- **Standard 14 フォント**は純粋な AFM メトリクスとして同梱されます（フォントファイルの
  パースは行いません）。
- コンパイル済み `.mpb` テンプレート（MejiroPDF Studio が生成）は、実行時の XML/JSON
  パースなしでロードされます。レイアウトの計算はファイルがサーバへ届く前に済んでいます。

## 実装済みの範囲（第1期）

| 領域 | クラス |
| --- | --- |
| ストリーム | `SafeStreamWriter`、`StreamWriterInterface` |
| ライター | `DocumentWriter`（ヘッダー / オブジェクト / xref / トレーラー） |
| ファサード | `Document`（`addPage`、`text`、`line`、`rect`、`save`、`output`） |
| フォント | `StandardFont`（Standard 14）、`CidFontJapanese`（CJK・非埋込）、`TrueTypeFont`（埋込）、メトリクス、`FontRegistry` |
| テキスト | `TextWrapper`、`JapaneseTextEncoder`（UTF-16BE）、`PdfEscaper` |
| ページ | `PageSize`、`Orientation`、`Alignment`、`PageNumberOptions`、`CreditOptions` |
| レイアウト | ページ番号（`{PAGE}/{TOTAL}`・総ページ数は正確）、ストリーミング `Table`（ゼブラ行、罫線あり / 横罫のみ / 罫線なし） |
| グラフィック | 直線、矩形、円 / 楕円、角丸矩形、線形 / 放射状グラデーション |
| ナビゲーション | Web / 内部リンク、しおり（入れ子のアウトライン）、複数ページ対応の目次（点線リーダー、クリック可能、2パス） |
| セキュリティ | AES-128 暗号化、detached PKCS#7 署名（`Signature`）、PDF/A-1b（`pdfA: true`） |
| インポート | 既存 PDF のページをベクター背景として埋め込み（`PdfPageImporter`） |
| テンプレート | `.mpb` のローダー / ライター（`MpbWriter`、`CompiledTemplateLoader`）、`TemplateRenderer` |
| 画像 | JPEG（`DCTDecode` パススルー）＋ PNG（`Image`、不透明およびアルファ / `SMask`） |
| 例外 | `ErrorCode`、`MejiroPDFException`、`AbstractPdfException`、… |

### 画像

```php
$pdf->image('logo.png', x: 20, y: 20, width: 40);   // 高さはアスペクト比から自動算出
$pdf->image('photo.jpg', x: 20, y: 70, width: 80, height: 50);
```

JPEG はそのままストリームされ（`/DCTDecode`）、PNG は PNG プレディクタ経由で埋め込まれ、
透過 PNG はカラー画像とグレースケールのソフトマスクに分割されます。いずれもメモリ使用量は
一定のままです。[`examples/image.php`](examples/image.php) を参照してください。

### コンパイル済みテンプレート（`.mpb`）

MejiroPDF Studio が JSON のデザインを `.mpb` バイナリへコンパイルします。エンジンは実行時の
パースなしでこれをロードし、リクエストごとのデータを流し込みます。

```php
use MejiroPDF\Template\CompiledTemplateLoader;

$template = CompiledTemplateLoader::loadFile('invoice.mpb');
$pdf->renderTemplate($template, ['customer' => '株式会社メジロ', 'total' => '¥1,300,000']);
```

[`examples/template.php`](examples/template.php) を参照してください。`.mpb` コンテナは
MPBF フォーマットであり、[`src/Template/Mpb.php`](src/Template/Mpb.php) のクラス
docblock に説明があります。

### 日本語（CJK）

```php
use MejiroPDF\Font\CidFontJapanese;

$pdf->addPage()->text(20, 30, '請求書', font: CidFontJapanese::HEISEI_KAKU_GO, size: 24.0);
```

内蔵の `HeiseiKakuGo-W5` / `HeiseiMin-W3`（Adobe-Japan1 CIDFont）を使用します。埋め込みは
行わず、テキストは `UniJIS-UTF16-H` CMap を通じて UTF-16BE でエンコードされます。
[`examples/japanese.php`](examples/japanese.php) を参照してください。

### TrueType の埋め込み

```php
use MejiroPDF\Font\TrueTypeFont;

$font = TrueTypeFont::fromFile('/path/to/DejaVuSans.ttf');
$pdf->addPage()->text(20, 30, 'café — ¥ — “quotes”', font: $font, size: 16.0);
```

フォントは実際に使用したグリフだけに**サブセット化**され（複合グリフとその依存関係も含む）、
ToUnicode CMap 付きの CIDFontType2（Identity-H）として埋め込まれます。そのためアクセント付き
ラテン文字や記号がどの環境でも表示され、コピー＆ペーストも可能なまま、ファイルサイズは
ごく一部で済みます（757 KB の DejaVuSans が、典型的な 1 ページで約 50 KB）。
[`examples/truetype.php`](examples/truetype.php) を参照してください。

### ナビゲーション

```php
$pdf->tableOfContents('Contents');          // finalize 時に内容が埋まる（2パス）
$pdf->addPage()->bookmark('Chapter 1');     // 入れ子のアウトライン。子には level を指定
$pdf->link(20, 55, 60, 7, 'https://mejiropdf.com');   // クリック可能な Web リンク
$pdf->linkToPage(20, 65, 60, 7, 3);          // 3 ページ目へジャンプ
```

目次は finalize 時に `bookmark()` の項目から構築されます（ページ番号は 2 パスで解決）。
必要なページ数だけ流し込まれ、各タイトルからページ番号まで点線リーダーを引き、すべての項目が
対象へのクリック可能なリンクになります。文書全体のページ番号は、目次自身が占めるページ数を
織り込んで算出されます。[`examples/report.php`](examples/report.php) を参照してください。

### 暗号化

```php
use MejiroPDF\Security\Encryption;

$pdf = new Document(encryption: new Encryption(
    userPassword: 'secret',           // 開くために必要
    ownerPassword: 'owner',           // 全権限を解除する
    permissions: Encryption::NO_COPY, // 例: テキストのコピーを禁止
));
```

すべての文字列とストリームが AES-128（標準セキュリティハンドラ、`/V 4 /R 4`、AESV2）で
暗号化されます。[`examples/encrypted.php`](examples/encrypted.php) を参照してください。

### 電子署名

```php
use MejiroPDF\Security\Signature;

$pdf = new Document(signature: Signature::fromFiles('cert.pem', 'key.pem', name: 'Acme'));
$pdf->addPage()->text(20, 30, 'Signed');
$pdf->save('signed.pdf');   // 検証: pdfsig signed.pdf
```

AcroForm の署名フィールドと、文書のバイトレンジに対する detached PKCS#7/CMS 署名
（`adbe.pkcs7.detached`、SHA-256）を ext-openssl 経由で付与します。
[`examples/signed.php`](examples/signed.php) を参照してください。

### PDF/A-1b（長期保存）

```php
$pdf = new Document(pdfA: true);
$pdf->setDefaultFont(TrueTypeFont::fromFile('DejaVuSans.ttf')); // 埋め込みが必須
$pdf->addPage()->text(20, 30, 'Archived');
$pdf->save('archive.pdf');   // 検証: verapdf --flavour 1b archive.pdf
```

pdfaid の XMP メタデータ（part 1、conformance B）、sRGB の `GTS_PDFA1` 出力インテント
（ICC プロファイル埋込）、文書 `/ID` を持つ PDF 1.4 を生成します。PDF/A は非埋込フォントと
暗号化を禁じるため、すべてのフォントは `TrueTypeFont` でなければなりません。Standard 14
フォント、和文 CID フォント、ページ番号、暗号化は明確なエラーで拒否されます。
[`examples/pdfa.php`](examples/pdfa.php) を参照してください。

### PDF の背景インポート

```php
$pdf->addPage()
    ->importPageAsBackground('letterhead.pdf')     // ページ全面に敷く
    ->text(60, 40, 'INVOICE', size: 24.0);         // その上に動的データ

$pdf->importPage('logo.pdf', 1, x: 150, y: 12, width: 40); // 位置を指定して配置
```

読み込んだページは Form XObject になります（リソースはコピーされ、番号が再マップされます）。
classic xref、PNG プレディクタ付きの相互参照ストリーム / オブジェクトストリーム（PDF 1.5+）、
**ハイブリッド参照**ファイル（classic テーブル ＋ `/XRefStm`）、祖先の `/Pages` ノードから
継承された `/Resources` に対応します。暗号化されたソースは明確なエラーになります。
[`examples/import_background.php`](examples/import_background.php) を参照してください。

### ログ

エンジンは任意の PSR-3 ロガーを受け取り、渡されなければ何も記録しません。

```php
use MejiroPDF\Log\FileLogger;

$pdf = new Document(logger: $monolog);                        // フレームワークのロガー
$pdf = new Document(logger: new FileLogger('/var/log/mp'));   // → mp_20260726.log
$pdf = new Document();                                        // NullLogger: 何も記録しない
```

`FileLogger` は便宜的なものであり方針ではありません。日次のファイル 1 つへ追記するだけで、
ローテーション・圧縮・削除は**行いません** — それが必要なら Monolog を渡してください。
診断の 1 行を失うことが文書を失う代償になってはならないので、決して例外を投げません。

ログ量は*問題の件数*に比例し、出力の大きさには比例しません。正常な文書は、1 ページでも
10 万ページでも 2 行（生成時に 1 行、finalize 時に 1 行）だけを記録します。それ以外は、
解決できなかったテンプレート画像をスキップしたときのように、エンジンが何かを縮退させた
場合にのみ出力されます。

**`.env` のサポートは意図的にありません。** 設定はコンストラクタ引数・`readonly` の
オプションオブジェクト・明示的なセッターから届きます。ライブラリが環境変数を読むと、
設定が隠れたグローバル状態になってしまうためです。

### 上限

```php
$pdf->setMaxPageLimit(5000);   // 上限を超えたページで MP-CORE-PAGE_OVERFLOW
$pdf->setMaxPageLimit(0);      // 上限なし（既定）
```

想定外に 100 万行を返したクエリで駆動される帳票に対する安全弁です。これが無いと、
処理はリクエストがタイムアウトするまでページを流し続け、失敗の原因が何も分かりません。

### ロードマップ（次の予定）

- 第2期 / 第3期: チャンク・ストリームによる画像スケーリング、テンプレート PDF へのテキスト
  差込編集、ほか。

## 開発

```bash
composer install
composer test     # PHPUnit 10
composer stan     # PHPStan level 8
```

## コントリビューションとサポート

**プルリクエストは受け付けていません** — デュアルライセンスは著作権が単一の著者に留まる
ことを前提とするため、第三者のコードをマージできません。**不具合報告は歓迎**しており、
ライブラリの改善に活用します。修正は著者が書きます。**サポートの約束はありません**:
本プロジェクトは他の仕事と並行して 1 人で維持されており、返答の期限は約束できません。

何かを起票する前に [CONTRIBUTING.md](./CONTRIBUTING.md) をお読みください。セキュリティ上の
脆弱性は公開 issue ではなく <support@2bo-velocity.com> へ非公開でご連絡ください。

## ライセンス

デュアルライセンス: **AGPL-3.0-or-later**（オープンソース。「Powered by MejiroPDF」の
フッタークレジット表示が必要）**または商用ライセンス**（クレジットの削除、優先サポート）。
リポジトリの `LICENSE.md` を参照してください。

© 2026 Yusuke Tsubonoue — https://mejiropdf.com
