<?php
/**
 * Template Name: PT Live Ranking
 * Description: Livestream ranking page inspired by the supplied reference image.
 */
defined('ABSPATH') || exit;

get_header();

$streams = [
    ['rank'=>1, 'title'=>'【素人couple初配信】瀬吹き勝負??ホコタテ??', 'meta'=>'彼女と旦那♡ 初コラボ配信', 'viewers'=>370, 'img'=>'https://images.unsplash.com/photo-1517841905240-472988babdf9?w=160&h=100&fit=crop'],
    ['rank'=>2, 'title'=>'AV配信★ 令和８年８月８日はマッサージの日？ 特集 ★', 'meta'=>'突然女子ちゃん', 'viewers'=>323, 'img'=>'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=160&h=100&fit=crop'],
    ['rank'=>3, 'title'=>'保険・雑談・美容・音楽・番組情報をご確認ください', 'meta'=>'かなえ屋＠千葉', 'viewers'=>305, 'img'=>'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=160&h=100&fit=crop'],
    ['rank'=>4, 'title'=>'&lt;;&lt;実験部屋(次回おすすめ)なAV作品', 'meta'=>'すず', 'viewers'=>184, 'img'=>'https://images.unsplash.com/photo-1488426862026-3ee34a7d66df?w=160&h=100&fit=crop'],
    ['rank'=>5, 'title'=>'音楽♡8/8初有料♡みなさん見に来てくださいネ', 'meta'=>'音楽♡初配信', 'viewers'=>177, 'img'=>'https://images.unsplash.com/photo-1531123897727-8f129e1688ce?w=160&h=100&fit=crop'],
    ['rank'=>6, 'title'=>'東京旅', 'meta'=>'旅先から配信中', 'viewers'=>168, 'img'=>'https://images.unsplash.com/photo-1540959733332-eab4deabeeaf?w=160&h=100&fit=crop'],
    ['rank'=>7, 'title'=>'今日もゆるっと配信', 'meta'=>'ライブ配信中', 'viewers'=>157, 'img'=>'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=160&h=100&fit=crop'],
    ['rank'=>7, 'title'=>'今日もゆるっと配信', 'meta'=>'ライブ配信中', 'viewers'=>157, 'img'=>'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=160&h=100&fit=crop'],
    ['rank'=>8, 'title'=>'今日もゆるっと配信', 'meta'=>'ライブ配信中', 'viewers'=>157, 'img'=>'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=160&h=100&fit=crop'],
    ['rank'=>9, 'title'=>'今日もゆるっと配信', 'meta'=>'ライブ配信中', 'viewers'=>157, 'img'=>'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=160&h=100&fit=crop'],
    ['rank'=>10, 'title'=>'今日もゆるっと配信', 'meta'=>'ライブ配信中', 'viewers'=>157, 'img'=>'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=160&h=100&fit=crop'],
    ['rank'=>11, 'title'=>'今日もゆるっと配信', 'meta'=>'ライブ配信中', 'viewers'=>157, 'img'=>'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=160&h=100&fit=crop'],
    ['rank'=>12, 'title'=>'今日もゆるっと配信', 'meta'=>'ライブ配信中', 'viewers'=>157, 'img'=>'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=160&h=100&fit=crop'],
    ['rank'=>13, 'title'=>'今日もゆるっと配信', 'meta'=>'ライブ配信中', 'viewers'=>157, 'img'=>'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=160&h=100&fit=crop'],
];

$surging = [
    ['title'=>'♡♡♡', 'meta'=>'🚀 +6人 (600%↑)', 'viewers'=>'7人', 'img'=>'https://images.unsplash.com/photo-1512316609839-ce289d3eba0a?w=120&h=70&fit=crop'],
    ['title'=>'こんなの初めてかも!!!', 'meta'=>'🚀 +9人 (225%↑)', 'viewers'=>'13人', 'img'=>'https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=120&h=70&fit=crop'],
    ['title'=>'がんばるね♡', 'meta'=>'🚀 +10人 (167%↑)', 'viewers'=>'16人', 'img'=>'https://images.unsplash.com/photo-1508214751196-bcfd4ca60f91?w=120&h=70&fit=crop'],
    ['title'=>'ねぇ、こっちみて？', 'meta'=>'🚀 +16人 (145%↑)', 'viewers'=>'27人', 'img'=>'https://images.unsplash.com/photo-1515886657613-9f3515b0c78f?w=120&h=70&fit=crop'],
    ['title'=>'帰り道少しだけ(^^)', 'meta'=>'🚀 +15人 (125%↑)', 'viewers'=>'27人', 'img'=>'https://images.unsplash.com/photo-1529139574466-a303027c1d8b?w=120&h=70&fit=crop'],
];

$recent = [
    ['title'=>'かえで', 'img'=>'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=120&h=70&fit=crop'],
];

$featured = [
    '【素人couple初配信】瀬吹き勝負??ホコタテ??',
    '【初配信！！】人気が先に隠れてイケないデビュー',
    '今日だけ限定ライブ。遊びに来てね！'
];
?>

<div class="ptlr-page">
    <div class="ptlr-wrap">

        <header class="ptlr-header">
            <div class="ptlr-brand">G-Maps<span>ライブ ランキング</span></div>
            <div class="ptlr-subtitle">G-Maps ライブ配信者の統計・分析サイト</div>
            <div class="ptlr-count">4,027人の配信者を掲載中</div>
            <button class="ptlr-icon-btn" aria-label="menu">▣</button>
        </header>

        <section class="ptlr-stats">
            <div class="ptlr-stat"><strong>733人</strong><span>現在配信中</span></div>
            <div class="ptlr-stat"><strong>877人</strong><span>本日ピーク</span></div>
            <div class="ptlr-stat"><strong>4,027人</strong><span>累計配信者</span></div>
        </section>

        <div class="ptlr-grid">

            <main>
                <section class="ptlr-panel">
                    <div class="ptlr-panel-head">
                        <h2>👑 視聴者数ランキング</h2>
                        <span>リアルタイム</span>
                    </div>

                    <div class="ptlr-tabs">
                        <button class="is-active" data-filter="all">▣ 全て</button>
                        <button data-filter="素人">👤 素人</button>
                        <button data-filter="初配信">🎁 初配信</button>
                        <button data-filter="エッチ">💋 エッチ</button>
                        <button data-filter="コスプレ">🎭 コスプレ</button>
                        <button data-filter="人妻">👩 人妻</button>
                        <button data-filter="オナ">🔴 オナ</button>
                        <button data-filter="新人">🧩 新人</button>
                    </div>

                    <div class="ptlr-list-head">
                        <span>No.</span><span>配信者</span><span>視聴者</span>
                    </div>

                    <div class="ptlr-stream-list">
                        <?php foreach ($streams as $stream): ?>
                            <article class="ptlr-stream" data-title="<?php echo esc_attr(wp_strip_all_tags($stream['title'])); ?>">
                                <div class="ptlr-rank <?php echo $stream['rank'] <= 3 ? 'top' : ''; ?>">
                                    <?php echo esc_html($stream['rank']); ?>
                                </div>
                                <img class="ptlr-avatar" src="<?php echo esc_url($stream['img']); ?>" alt="">
                                <div class="ptlr-stream-info">
                                    <a href="#" class="ptlr-stream-title"><?php echo wp_kses_post($stream['title']); ?></a>
                                    <div class="ptlr-stream-meta"><?php echo esc_html($stream['meta']); ?></div>
                                </div>
                                <div class="ptlr-viewers"><?php echo number_format_i18n($stream['viewers']); ?>人</div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </main>

            <aside class="ptlr-sidebar">

                <section class="ptlr-side-panel surge">
                    <div class="ptlr-side-head">🚀 急上昇中</div>
                    <?php foreach ($surging as $item): ?>
                        <article class="ptlr-side-row">
                            <img src="<?php echo esc_url($item['img']); ?>" alt="">
                            <div>
                                <strong><?php echo esc_html($item['title']); ?></strong>
                                <small><?php echo esc_html($item['meta']); ?></small>
                            </div>
                            <b><?php echo esc_html($item['viewers']); ?></b>
                        </article>
                    <?php endforeach; ?>
                </section>

                <section class="ptlr-side-panel">
                    <div class="ptlr-side-head">◉ 最近見た配信者</div>
                    <?php foreach ($recent as $item): ?>
                        <article class="ptlr-recent">
                            <img src="<?php echo esc_url($item['img']); ?>" alt="">
                            <span><?php echo esc_html($item['title']); ?></span>
                        </article>
                    <?php endforeach; ?>
                </section>

                <section class="ptlr-side-panel featured">
                    <div class="ptlr-side-head">✨ 注目の配信</div>
                    <?php foreach ($featured as $title): ?>
                        <a href="#" class="ptlr-featured-item"><?php echo esc_html($title); ?></a>
                    <?php endforeach; ?>
                </section>

            </aside>
        </div>
    </div>

    <div class="ptlr-float-tools" aria-label="quick tools">
        <button title="Layout">◫</button>
        <button title="History">◉</button>
        <button title="Users">♧</button>
        <button title="Live" class="live">◉</button>
    </div>
</div>

<?php get_footer(); ?>
