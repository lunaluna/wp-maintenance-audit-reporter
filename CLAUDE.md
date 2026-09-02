# CLAUDE.md

このファイルは、このリポジトリ(WP Maintenance Audit Reporter, WPMAR)で作業する Claude Code への
ガイダンスです。

## このプラグインについて

WordPress サイトの保守監査レポート(コア/プラグイン/テーマの更新状況、セキュリティ、パフォーマンス
など)を生成し、管理者向け・クライアント向けの2種類の文面で配信するプラグイン。単一サイトだけでなく
マルチサイト(ネットワーク集約監査)を主要機能として提供している(`README.md` / `README-ja.md` の
"Multisite" / "マルチサイト対応" の項、CLI の `--network` オプション、v1.4.0 のマルチサイト
メモリ対策リリースを参照)。

## マルチサイト対応は必須要件(絶対ルール)

**このプラグインは「マルチサイトでも正しく動作すること」を保証する製品である。**
マルチサイトはオプション機能ではなく中核機能のひとつなので、**このプラグインへのすべての開発・
修正・編集(バグ修正・新機能追加・リファクタを問わず)において、マルチサイトでの動作検証を必ず
検討・実施すること。**

具体的には:

- 変更がネットワークロールアップ経路(`includes/class-wpmar-network-runner.php` の
  `switch_to_blog()` ループ、`includes/class-wpmar-network.php` の `on_blog()` / `on_main_site()`)
  にどう影響するかを、コードを読んで机上確認する。単一サイト実行と同じコードパスを通るのか、
  ネットワーク側に専用の分岐があるのかを確認すること。
- 新しいキャッシュ・トランジェント・オプションを追加するときは、`site_transient` /
  `network option`(ネットワーク全体で共有すべきもの)と、per-blog `transient` / `option`
  (サイトごとに独立すべきもの)のどちらが適切かを都度判断する。誤って per-blog にすると
  ネットワークロールアップのループ内で毎サイト再取得してしまい、誤って site 単位にすると
  サイト間でデータが混線する。
- ファイルパス・ストレージを扱う変更は、`wp-content` がネットワーク全体で共有される前提を踏まえ、
  `site-{blog_id}/` のようなサイト分離が必要かを確認する。
- 可能であればマルチサイト環境で実機検証する。ローカル環境がシングルサイト構成で実機検証できない
  場合は、その旨をユーザーに申告し、プランの検証セクションに「未検証」として明記する
  (実装を「検証済み」と偽って報告しない)。
- プランを作成するときは、検証セクションにマルチサイト観点の確認項目を必ず1つ以上含める。

### 参考実装

- `includes/class-wpmar-network-runner.php` — `switch_to_blog()` ループでネットワークロールアップ
  を回す本体。単一サイトと同じ `WPMAR_Data_Collector::gather()` / `WPMAR_Runner::render_*_markup()`
  を経由する。
- `includes/class-wpmar-network.php` — `get_sites()` / `switch_to_blog()` まわりの薄いファサード。
- `includes/api/class-wpmar-wporg-client.php` の `cache_key_for()` — wp.org 由来のメタデータを
  `site_transient` でネットワーク全体キャッシュする設計の参考実装(docblock に理由が書かれている)。
