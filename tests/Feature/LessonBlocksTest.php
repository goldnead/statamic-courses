<?php

use Goldnead\Courses\Support\LessonBlocks;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\PrivateMedia\PrivateMedia;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

function renderTemplate(string $template, array $data = []): string
{
    $path = sys_get_temp_dir().'/courses-blocks-'.bin2hex(random_bytes(6)).'.antlers.html';
    file_put_contents($path, $template);

    try {
        return trim(view()->file($path, $data)->render());
    } finally {
        @unlink($path);
    }
}

beforeEach(function () {
    // With a URL: a container without one is not publicly reachable, and a
    // download from it is left out (see the private-media test below).
    Storage::fake('assets', ['url' => '/assets']);
    AssetContainer::make('assets')->disk('assets')->save();
    Storage::disk('assets')->put('noten/satz.pdf', str_repeat('x', 2048));

    $this->course = $this->makeCourse('choir', ['sequencing_mode' => 'lesson']);
    $this->lesson = $this->makeLesson($this->course, 'warmup', [
        'sort_order' => 1,
        'item_type' => 'text',
        'content' => 'Older **markdown** text.',
        'blocks' => [
            ['id' => 'b1', 'type' => 'text', 'enabled' => true, 'text' => 'Breathe *in*.'],
            ['id' => 'b2', 'type' => 'callout', 'tone' => 'warning', 'heading' => 'Careful', 'text' => 'Not too loud.'],
            ['id' => 'b3', 'type' => 'columns', 'columns' => [['text' => 'Left'], ['text' => 'Right']]],
            ['id' => 'b4', 'type' => 'faq', 'items' => [['question' => 'Why <hum>?', 'answer' => 'It *warms*.'], ['question' => '', 'answer' => 'dropped']]],
            ['id' => 'b5', 'type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'caption' => 'Demo'],
            ['id' => 'b6', 'type' => 'download', 'file' => 'noten/satz.pdf', 'label' => 'Sheet music'],
            ['id' => 'b7', 'type' => 'button', 'label' => 'Book a lesson', 'link' => 'https://example.test/book', 'style' => 'secondary'],
            ['id' => 'b8', 'type' => 'text', 'enabled' => false, 'text' => 'Switched off.'],
            ['id' => 'b9', 'type' => 'download', 'file' => 'missing.pdf'],
            ['id' => 'b10', 'type' => 'download', 'file' => 'noten/satz.pdf', 'private' => true],
        ],
    ]);
    $this->makeLesson($this->course, 'later', ['sort_order' => 2, 'item_type' => 'text', 'blocks' => [['type' => 'text', 'text' => 'Later.']]]);

    $this->user = tap(User::make()->id('learner')->email('learner@example.test'))->save();
    Entitlements::grant(new SubjectReference('user', 'learner'), 'choir', 'test');
});

it('turns the stored blocks into template data, older content first, empty and switched-off blocks left out', function () {
    $blocks = app(LessonBlocks::class)->for(Entry::find($this->lesson), $this->user, 'choir');

    expect(array_column($blocks, 'type'))->toBe(['text', 'text', 'callout', 'columns', 'faq', 'video', 'download', 'button'])
        ->and($blocks[0]['html'])->toContain('<strong>markdown</strong>')
        ->and($blocks[2])->toMatchArray(['tone' => 'warning', 'heading' => 'Careful'])
        ->and($blocks[3]['count'])->toBe(2)
        ->and($blocks[4]['items'])->toHaveCount(1)
        ->and($blocks[5]['embed_url'])->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->and($blocks[6])->toMatchArray(['label' => 'Sheet music', 'filename' => 'satz.pdf', 'extension' => 'pdf', 'size' => '2 KB', 'private' => false])
        ->and($blocks[7])->toMatchArray(['url' => 'https://example.test/book', 'style' => 'secondary']);
});

it('drops a private download it cannot have signed', function () {
    PrivateMedia::$signs = false;

    $blocks = app(LessonBlocks::class)->for(Entry::find($this->lesson), $this->user, 'choir');

    expect(collect($blocks)->where('type', 'download')->pluck('private')->all())->toBe([false]);
});

it('links a private download through statamic-private-media, signed for the course product', function () {
    PrivateMedia::$signs = true;

    $blocks = app(LessonBlocks::class)->for(Entry::find($this->lesson), $this->user, 'choir');
    $private = collect($blocks)->where('type', 'download')->firstWhere('private', true);

    expect($private['url'])->toBe('/!/private-media/choir/noten/satz.pdf?signature=test')
        ->and($private['label'])->toBe('satz.pdf');

    PrivateMedia::$signs = false;
});

it('renders a lesson written before blocks exactly from its content field', function () {
    $old = $this->makeLesson($this->course, 'old', ['sort_order' => 3, 'content' => "# Title\n\nParagraph."]);

    $blocks = app(LessonBlocks::class)->for(Entry::find($old));

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['html'])->toContain('<h1>Title</h1>')->toContain('<p>Paragraph.</p>');
});

it('renders the shipped partials through the tag, escaping what authors typed', function () {
    $this->actingAs($this->user);

    $html = renderTemplate('{{ courses:blocks lesson="'.$this->lesson.'" }}');

    expect($html)
        ->toContain('<div class="courses-blocks">')
        ->toContain('<em>in</em>')
        ->toContain('courses-callout--warning')
        ->toContain('<summary class="courses-faq__question">Why &lt;hum&gt;?</summary>')
        ->toContain('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->toContain('>Sheet music</a>')
        ->toContain('(PDF, 2 KB)')
        ->toContain('class="courses-button courses-button--secondary" href="https://example.test/book"')
        ->not->toContain('Switched off.');
});

it('hands the blocks to a pair for a site that draws them itself', function () {
    $this->actingAs($this->user);

    expect(renderTemplate('{{ courses:blocks lesson="'.$this->lesson.'" }}{{ type }},{{ /courses:blocks }}'))
        ->toBe('text,text,callout,columns,faq,video,download,button,');
});

it('renders nothing for a guest, a learner without access, or a locked lesson', function () {
    $template = '{{ courses:blocks lesson="'.$this->lesson.'" }}';
    expect(renderTemplate($template))->toBe('');

    $stranger = tap(User::make()->id('stranger')->email('s@example.test'))->save();
    $this->actingAs($stranger);
    expect(renderTemplate($template))->toBe('');

    $this->actingAs($this->user);
    expect(renderTemplate('{{ courses:blocks lesson="'.Entry::query()->where('slug', 'later')->first()->id().'" }}'))->toBe('');
});

it('lets a super user preview any lesson', function () {
    $admin = tap(User::make()->id('admin')->email('admin@example.test')->makeSuper())->save();
    $this->actingAs($admin);

    expect(renderTemplate('{{ courses:blocks lesson="'.Entry::query()->where('slug', 'later')->first()->id().'" }}'))->toContain('Later.');
});

it('turns YouTube and Vimeo links into player links and leaves other videos alone', function (string $url, ?string $embed) {
    expect(LessonBlocks::embedUrl($url))->toBe($embed);
})->with([
    ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
    ['https://youtube.com/shorts/abcdefgh', 'https://www.youtube-nocookie.com/embed/abcdefgh'],
    ['https://vimeo.com/123456', 'https://player.vimeo.com/video/123456'],
    ['https://cdn.example.test/v.mp4', null],
]);
