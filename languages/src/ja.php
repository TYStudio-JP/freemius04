<?php
/**
 * Japanese translations, written by hand: English => Japanese.
 *
 * `php bin/i18n.php ja` builds languages/upgrade-warden-ja.po and .mo from this
 * file and from ja-rules.php (the generated sentences of the rules).
 * `php bin/i18n.php check` fails when a string of the plugin is missing here.
 *
 * @package UpgradeWarden
 */

// phpcs:disable

return array(
	// Names and navigation.
	'Upgrade Warden' => 'Upgrade Warden',
	'Scan' => '検査',
	'Run-time log' => '実行時の記録',
	'recording' => '記録中',
	'Plugin' => 'プラグイン',
	'Theme' => 'テーマ',
	'Must-use plugins' => '必須プラグイン（mu-plugins）',
	'WordPress itself' => 'WordPress 本体',
	'All plugins and themes' => 'すべてのプラグインとテーマ',
	'inactive' => '停止中',
	'You are not allowed to do this.' => 'この操作を行う権限がありません。',

	// Header of the screen.
	'This site runs PHP %s.' => 'このサイトは PHP %s で動いています。',
	'Show what happens on:' => '次の版にしたときの結果を表示:',
	'What is new in PHP %s comes from the official upgrade notes. It was not run on a real PHP of that version yet, and is marked as such below.' => 'PHP %s で新しく変わる点は、公式の変更記録にもとづいています。その版の実物の PHP ではまだ動かして確かめていないため、下の一覧でもそのように表示します。',
	'Scan plugins and themes' => 'プラグインとテーマを検査',
	'Last scan: %s.' => '前回の検査: %s',
	'A scan reads the PHP files of your plugins and themes. It changes nothing, and nothing leaves your site. It cannot see problems that depend on the data a page handles; those only show when the code runs, and the run-time log is there for them.' => '検査では、プラグインとテーマの PHP ファイルを読み取ります。何も書き換えず、サイトの外へは何も送りません。ページが扱うデータによって起きる問題は、検査では分かりません。そうした問題はコードが実際に動いたときにだけ現れるので、「実行時の記録」で確かめます。',
	'Scanning… %1$d of %2$d plugins and themes done.' => '検査中… プラグインとテーマ %2$d 件のうち %1$d 件が完了しました。',
	'The scan stopped. Reload the page and start it again; files already scanned are not scanned twice.' => '検査が止まりました。ページを読み込み直して、もう一度始めてください。検査済みのファイルをもう一度検査することはありません。',

	// Overview.
	'Plugin or theme' => 'プラグイン・テーマ',
	'On PHP %s' => 'PHP %s での結果',
	'Will break' => '動かなくなる',
	'Behaves differently' => '動きが変わる',
	'Will behave differently' => '動きが変わる',
	'Deprecated' => '非推奨',
	'Deprecation notices' => '非推奨の通知が出る',
	'Behind a check' => 'チェックの内側',
	'Files' => 'ファイル数',
	'Could not be read' => '読み取れなかった',
	'Not scanned yet' => '未検査',
	'Nothing found' => '問題なし',
	'Upgrade Warden Pro fixes what can be fixed automatically, with a preview, a backup and an undo. It also scans every week and after updates, e-mails you when something new will break, keeps the run-time log for 90 days and exports a report.' => 'Upgrade Warden Pro は、自動で直せる箇所を修正します。変更内容の確認、バックアップ、元に戻す操作が付いています。ほかに、毎週と更新後の自動検査、動かなくなる箇所が新しく見つかったときのメール通知、実行時の記録の90日間保存、報告書の書き出しができます。',
	'See Upgrade Warden Pro' => 'Upgrade Warden Pro を見る',

	// Findings of one plugin or theme.
	'%1$s on PHP %2$s' => '%1$s（PHP %2$s での結果）',
	'%1$s could not be read as PHP, so it was not checked: %2$s' => '%1$s は PHP として読み取れなかったため、検査していません: %2$s',
	'Not scanned yet.' => 'まだ検査していません。',
	'Nothing found for this PHP version.' => 'この PHP の版では、問題は見つかりませんでした。',
	'Where' => '場所',
	'What' => '内容',
	'What happens' => '起きること',
	'Use %s instead.' => '代わりに %s を使います。',
	'From the official upgrade notes; not yet run on a real PHP of this version.' => '公式の変更記録にもとづく内容です。この版の実物の PHP ではまだ確かめていません。',
	'Can be fixed automatically.' => '自動で修正できます。',
	'Can be fixed automatically with Upgrade Warden Pro.' => 'Upgrade Warden Pro なら自動で修正できます。',
	'Stops working from PHP %s.' => 'PHP %s から動かなくなります。',
	'Behaves differently from PHP %s, without a warning.' => 'PHP %s から、警告なしに動きが変わります。',
	'Deprecated from PHP %s: still works, raises a notice, and will be removed later.' => 'PHP %s から非推奨です。まだ動きますが通知が出て、将来の版で使えなくなります。',
	'Behind a check, so it should not run on the newer PHP.' => 'チェックの内側にあるため、新しい PHP では実行されないはずです。',
	'Rule %1$s %2$s' => '規則 %1$s %2$s',

	// Sentences with the subject inside.
	'Calls %s(), a function PHP no longer has.' => 'PHP からなくなった関数 %s() を呼び出しています。',
	'Calls %s(), which PHP has deprecated.' => 'PHP が非推奨にした関数 %s() を呼び出しています。',
	'Uses the constant %s, which PHP no longer has.' => 'PHP からなくなった定数 %s を使っています。',
	'Uses the constant %s, which PHP has deprecated.' => 'PHP が非推奨にした定数 %s を使っています。',
	'Reads an array or string offset with curly braces, as in $text{0}.' => '配列や文字列の位置を、$text{0} のように波かっこで指定しています。',
	'Uses the (real) cast.' => '(real) キャストを使っています。',
	'Uses the (unset) cast.' => '(unset) キャストを使っています。',
	'Uses a long cast name such as (integer) or (boolean).' => '(integer) や (boolean) のような長い名前のキャストを使っています。',
	'Chains ternary operators without parentheses.' => '三項演算子をかっこなしで続けて書いています。',
	'The class %s has a constructor named after the class. PHP no longer calls it when an object is created, and gives no warning.' => 'クラス %s に、クラスと同じ名前のコンストラクターがあります。PHP はオブジェクトを作るときにこれを呼ばなくなり、警告も出しません。',
	'Uses %s as a function name; it is now a reserved word.' => '関数名に %s を使っています。これは予約語になりました。',
	'Has a %s statement with no loop or switch around it. The whole file fails to load.' => 'ループや switch の外に %s 文があります。ファイル全体が読み込めなくなります。',
	'Runs a command with the backtick operator.' => 'バッククォート演算子でコマンドを実行しています。',
	'Ends a case with a semicolon instead of a colon.' => 'case の終わりがコロンではなくセミコロンになっています。',
	'The parameter %s has a type and a null default, but the type does not say it may be null.' => '引数 %s は、型があり初期値が null ですが、型に null を許す指定がありません。',
	'The optional parameter %s comes before a required one.' => '省略できる引数 %s が、省略できない引数より前にあります。',
	'Writes a variable inside a string as ${name}.' => '文字列の中の変数を ${name} の形で書いています。',

	// Run-time log.
	'What PHP says while the site runs' => 'サイトが動いているあいだに PHP が出すメッセージ',
	'A scan reads code. It cannot see a problem that depends on the data a page handles, such as counting something that turns out to be empty. PHP 7 answers many of those with a warning and carries on; PHP 8 stops the page. While recording is on, Upgrade Warden notes these messages as they happen, so you can see them before you switch PHP, and see what stopped a page after you did.' => '検査はコードを読むだけなので、ページが扱うデータによって起きる問題は分かりません。たとえば、数えようとした中身が空だった場合です。PHP 7 はその多くを警告だけで済ませて先へ進みますが、PHP 8 はページを止めます。記録をオンにすると、Upgrade Warden はこうしたメッセージを出たときに書き留めます。PHP を切り替える前に確かめられ、切り替えた後は、何がページを止めたかが分かります。',
	'Recorded: deprecation notices and fatal errors of PHP itself, and the warnings known to become errors on a newer PHP. Kept: the message, the file and the line, for %d day after it was last seen. Not recorded: visitors, addresses, form data or page content. Nothing leaves your site.' => '記録するもの: PHP 自身が出す非推奨の通知と致命的なエラー、新しい PHP でエラーになると分かっている警告。保存するもの: メッセージ、ファイル、行番号（最後に出てから %d 日間）。記録しないもの: 訪問者、アドレス、フォームの入力内容、ページの内容。サイトの外へは何も送りません。',
	'Recording is on' => '記録中',
	'Recording is off' => '記録していません',
	'Start recording' => '記録を始める',
	'Stop recording' => '記録を止める',
	'Empty the log' => '記録を空にする',
	'Nothing recorded yet. Use the site as usual; messages appear here as PHP raises them.' => 'まだ記録はありません。サイトをふだんどおり使ってください。PHP がメッセージを出すと、ここに表示されます。',
	'Nothing recorded.' => '記録はありません。',
	'Last seen' => '最後に出た日時',
	'first: %s' => '最初: %s',
	'What PHP said' => 'PHP のメッセージ',
	'What it means on PHP %s' => 'PHP %s での意味',
	'This stopped the page on PHP %s.' => 'PHP %s で、このエラーがページを止めました。',
	'PHP made it an error in version %s; older versions let it pass.' => 'PHP %s でエラーになった書き方です。それより前の版では通っていました。',
	'Checked on real PHP versions.' => '実物の PHP で確かめた内容です。',
	'Not one of the PHP changes on record here; it may have another cause.' => 'このプラグインが記録している PHP の変更には当てはまりません。別の原因かもしれません。',
	'Fatal error' => '致命的なエラー',
	'Likely to break' => '動かなくなる可能性が高い',
	'PHP %s turns warnings of this kind into errors that stop the page, for almost every function.' => 'PHP %s は、ほぼすべての関数で、この種類の警告をページを止めるエラーに変えます。',
	'This particular function was not run on real PHP versions for this plugin, so this is not certain.' => 'この関数そのものは実物の PHP で確かめていないため、確実ではありません。',
	'Deprecation notice' => '非推奨の通知',
	'PHP %s marks this as deprecated. It still works; a later PHP version will stop accepting it.' => 'PHP %s が非推奨としている書き方です。まだ動きますが、将来の版で使えなくなります。',
	'Not among the messages on record here, so the versions are not known.' => 'このプラグインが記録しているメッセージにないため、どの版で変わるかは分かりません。',
	'No change on PHP %s' => 'PHP %s では変わらない',

	// Pro: fixes.
	'Automatic fixes' => '自動修正',
	'%d finding listed below can be fixed automatically.' => '下の一覧のうち %d 件は自動で修正できます。',
	'Show what would change' => '変更内容を確認する',
	'There is nothing to fix automatically.' => '自動で修正できる箇所はありません。',
	'These are the exact changes. Only the marked characters change; everything else in the files stays as it is. The fixed code also runs on the PHP version this site uses now, so you can fix first and switch PHP afterwards. The originals are kept, and the change can be undone below.' => '実際に行う変更は次のとおりです。印の付いた文字だけが変わり、ファイルのほかの部分はそのままです。修正後のコードは、このサイトが今使っている PHP の版でも動きます。先に修正してから PHP を切り替えられます。元のファイルは保存してあり、下のボタンで元に戻せます。',
	'Apply these fixes' => 'この修正を適用する',
	'From line %d' => '%d 行目から',
	'Fixed on %1$s: %2$d files.' => '%1$s に修正: %2$d ファイル',
	'Undo' => '元に戻す',
	'%1$d fixes made in %2$d files. The originals are kept; use Undo to put them back.' => '%2$d ファイルで %1$d か所を修正しました。元のファイルは保存してあります。「元に戻す」で戻せます。',
	'%d files put back as they were.' => '%d ファイルを元に戻しました。',
	'Left alone because they changed after the fix: %s' => '修正のあとに変更されていたため、そのままにしたファイル: %s',
	'The file %s is not writable. Nothing was changed.' => 'ファイル %s に書き込めません。何も変更していません。',
	'The backup folder could not be created. Nothing was changed.' => 'バックアップ用のフォルダーを作れませんでした。何も変更していません。',
	'A backup copy could not be written. Nothing was changed.' => 'バックアップを書き込めませんでした。何も変更していません。',
	'That backup does not exist.' => 'そのバックアップは見つかりません。',

	// Pro: automatic checks and e-mails.
	'Automatic checks' => '自動チェック',
	'Saved.' => '保存しました。',
	'Scan every week, and e-mail me when a plugin or theme has more code that will break than the week before.' => '毎週検査して、動かなくなる箇所が前の週より増えたプラグインやテーマがあれば、メールで知らせる。',
	'Scan again a minute after a plugin or theme is installed or updated.' => 'プラグインやテーマを入れたり更新したりした1分後に、もう一度検査する。',
	'E-mail me once a day when the run-time log recorded something new that stops a page.' => '実行時の記録に、ページを止めるメッセージが新しく入ったら、1日1回メールで知らせる。',
	'Send e-mails to:' => 'メールの送り先:',
	'No e-mail is sent when nothing got worse. E-mails are sent by this site, the way WordPress sends its own.' => '悪くなった点がなければ、メールは送りません。メールは、WordPress 自身のメールと同じように、このサイトから送ります。',
	'Save' => '保存',
	'Download the report' => '報告書をダウンロード',
	'%1$s: %2$d will break, %3$d will behave differently' => '%1$s: 動かなくなる %2$d か所、動きが変わる %3$d か所',
	'[%1$s] New PHP %2$s problems found' => '[%1$s] PHP %2$s で問題になる箇所が新しく見つかりました',
	'Upgrade Warden scanned the plugins and themes of this site. Since the scan before, these have more code that PHP %s will not run as before:' => 'Upgrade Warden がこのサイトのプラグインとテーマを検査しました。前回の検査と比べて、PHP %s でこれまでどおり動かないコードが増えたものは次のとおりです。',
	'File, line and what can be fixed automatically:' => 'ファイル、行番号、自動で修正できる箇所はこちら:',
	'[%1$s] PHP recorded %2$d new problem' => '[%1$s] PHP が新しい問題を %2$d 件記録しました',
	'While the site ran, PHP raised messages that stop a page now or will on PHP %s. Each is listed once, where it was first seen:' => 'サイトが動いているあいだに、いまページを止めている、または PHP %s でページを止めるメッセージを PHP が出しました。最初に出た場所ごとに1回ずつ載せています。',

	// Pro: report.
	'PHP %s compatibility report' => 'PHP %s 互換性の報告書',
	'The site runs PHP %1$s. Report made on %2$s.' => 'このサイトは PHP %1$s で動いています。報告書の作成日時: %2$s',
	'How to read this: "Will break" means PHP no longer runs the code. "Will behave differently" means it runs and does something else, without a warning. "Deprecation notices" means it still works and PHP announces that it will stop. Each statement about a PHP version was checked by running code on real PHP 7.4 to 8.5; what could only be taken from the official upgrade notes is marked.' => '読み方: 「動かなくなる」は、PHP がそのコードを実行しなくなることです。「動きが変わる」は、実行はされますが、警告なしに別の結果になることです。「非推奨の通知が出る」は、まだ動きますが、将来使えなくなると PHP が知らせている状態です。PHP の版についての記述は、実物の PHP 7.4〜8.5 でコードを動かして確かめています。公式の変更記録だけにもとづくものには、その旨を表示しています。',
	'Summary' => '概要',
	'Can be fixed automatically' => '自動で修正できる',
	'What PHP said while the site ran' => 'サイトが動いているあいだに PHP が出したメッセージ',

	// Freemius.
	'Thanks %s!' => '%s さん、ありがとうございます！',
);
