<?php

use Goldnead\Courses\Facades\Courses;
use Goldnead\Courses\Models\Enrollment;
use Goldnead\Courses\Models\LessonState;
use Goldnead\Courses\Support\LessonBlocks;
use Goldnead\Courses\Support\ProgressReport;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\PrivateMedia\Contracts\MediaAccess;
use Goldnead\PrivateMedia\PrivateMedia;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * A course of kind `material`: a page (or a few) with text, images and
 * downloads, opened by the same entitlement as a course, without progress,
 * sequencing or drip.
 */
function renderMaterial(string $template): string
{
    $path = sys_get_temp_dir().'/courses-material-'.bin2hex(random_bytes(6)).'.antlers.html';
    file_put_contents($path, $template);

    try {
        return trim(view()->file($path)->render());
    } finally {
        @unlink($path);
    }
}

beforeEach(function () {
    // private-media's container and files: three voicings of an arrangement.
    config()->set('private-media.source.container', 'private');
    Storage::fake('private');
    AssetContainer::make('private')->disk('private')->save();

    foreach (['satb.pdf', 'satb.mp3', 'ssa.pdf', 'readme.txt', 'figure.png'] as $file) {
        Storage::disk('private')->put('baraye/'.$file, str_repeat('x', 1024));
    }

    Storage::fake('assets', ['url' => '/assets']);
    AssetContainer::make('assets')->disk('assets')->save();
    Storage::disk('assets')->put('baraye/public.pdf', 'x');
    Storage::disk('assets')->put('baraye/cover.jpg', 'x');

    // Installed again now that both containers exist, as a site would have them.
    $this->artisan('courses:install', ['--force' => true])->run();

    $this->material = $this->makeCourse('baraye', [
        'title' => 'Baraye',
        'kind' => 'material',
        'summary' => 'Arrangement in three voicings',
        'cover' => 'baraye/cover.jpg',
        // Set on the entry before the kind changed; a material ignores them.
        'sequencing_mode' => 'lesson',
        'drip_mode' => 'days',
        'body' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Sing it slowly.']]],
            // The paid figure, from private-media's container.
            ['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['src' => 'asset::private::baraye/figure.png', 'alt' => 'Figure']]]],
        ],
        // One row per group, its files underneath.
        'downloads' => [
            ['id' => 'g1', 'group' => 'SATB', 'files' => [
                ['id' => 'd1', 'file' => 'baraye/satb.pdf', 'label' => 'Score'],
                ['id' => 'd3', 'file' => 'baraye/satb.mp3', 'label' => 'Rehearsal track'],
            ]],
            ['id' => 'g2', 'group' => 'SSA', 'files' => [
                ['id' => 'd2', 'file' => 'baraye/ssa.pdf', 'label' => 'Score'],
            ]],
            ['id' => 'g3', 'files' => [
                ['id' => 'd4', 'file' => 'baraye/readme.txt', 'label' => 'Notes'],
            ]],
        ],
    ]);

    $this->buyer = tap(User::make()->id('buyer')->email('buyer@example.test'))->save();
    $this->stranger = tap(User::make()->id('stranger')->email('stranger@example.test'))->save();
    Entitlements::grant(new SubjectReference('user', 'buyer'), 'baraye', 'test');
});

afterEach(function () {
    PrivateMedia::$signs = false;
});

describe('kind', function () {
    it('reads a course without a kind as a course, so existing courses change nothing', function () {
        $this->makeCourse('cvt-101');
        $this->makeCourse('odd', ['kind' => 'webinar']);

        expect(Courses::course('cvt-101'))->toMatchArray(['kind' => 'course', 'is_material' => false])
            ->and(Courses::course('odd')['kind'])->toBe('course')
            ->and(Courses::course('baraye'))->toMatchArray(['kind' => 'material', 'is_material' => true]);
    });

    it('reads a material as having neither sequencing nor drip, whatever the entry still carries', function () {
        expect(Courses::course('baraye'))->toMatchArray(['sequencing_mode' => 'none', 'drip_mode' => 'none']);
    });

    it('offers the kind in the course blueprint with Course as the default', function () {
        $kind = Blueprint::find('collections.courses.course')->field('kind');

        expect($kind->config()['default'])->toBe('course')
            ->and(array_keys($kind->config()['options']))->toBe(['course', 'material']);
    });

    it('adds kind, body and downloads to an older course blueprint on --merge and leaves the courses as they are', function () {
        Blueprint::make('course')->setNamespace('collections.courses')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'drip_mode', 'field' => ['type' => 'select', 'options' => ['none' => 'None']]],
            ]]]]],
        ])->save();
        $id = $this->makeCourse('old-course', ['drip_mode' => 'none']);
        $before = Entry::find($id)->data()->all();

        $this->artisan('courses:install', ['--merge' => true])->assertSuccessful();

        $blueprint = Blueprint::find('collections.courses.course');

        expect($blueprint->hasField('kind'))->toBeTrue()
            ->and($blueprint->hasField('body'))->toBeTrue()
            ->and($blueprint->hasField('downloads'))->toBeTrue()
            ->and(Entry::find($id)->data()->all())->toBe($before)
            ->and(Courses::course('old-course')['kind'])->toBe('course');
    });

    it('picks downloads from the private-media container only, and offers none without it', function () {
        $groupFields = collect(Blueprint::find('collections.courses.course')->field('downloads')->config()['fields'])->keyBy('handle');
        $fileFields = collect($groupFields['files']['field']['fields'])->keyBy('handle');

        expect($groupFields->keys()->all())->toBe(['group', 'files'])
            ->and($fileFields->keys()->all())->toBe(['file', 'label'])
            ->and($fileFields['file']['field'])->toMatchArray(['type' => 'assets', 'container' => 'private', 'max_files' => 1]);

        config()->set('private-media.source.container', null);
        $this->artisan('courses:install', ['--force' => true])->assertSuccessful();

        expect(Blueprint::find('collections.courses.course')->hasField('downloads'))->toBeFalse()
            ->and(Blueprint::find('collections.courses.course')->hasField('body'))->toBeTrue();
    });
});

/**
 * Whether a field's conditions hide it once the kind is `material`.
 *
 * @param  array<string, mixed>  $field
 */
function hiddenForMaterial(array $field): bool
{
    return ($field['unless']['kind'] ?? null) === 'equals material'
        || ($field['if']['kind'] ?? null) === 'not material';
}

const COURSE_ONLY_FIELDS = ['product', 'bundles', 'sequencing_mode', 'drip_mode', 'drip_day_of_month', 'on_payment_failure', 'team_seats', 'section_audiences'];

describe('form', function () {
    it('hides everything that needs lessons, pacing, a subscription or seats once the kind is Material', function () {
        $fields = Blueprint::find('collections.courses.course')->fields()->all();

        foreach (COURSE_ONLY_FIELDS as $handle) {
            expect(hiddenForMaterial($fields[$handle]->config()))->toBeTrue($handle.' stays visible for a material');
        }

        expect($fields['drip_day_of_month']->config()['if']['drip_mode'])->toBe('equals day_of_month')
            ->and(hiddenForMaterial($fields['title']->config()))->toBeFalse()
            ->and(hiddenForMaterial($fields['summary']->config()))->toBeFalse();
    });

    it('shows the kind as a column in the entries listing', function () {
        expect(Blueprint::find('collections.courses.course')->field('kind')->config()['listable'])->toBeTrue();
    });

    it('teaches an existing blueprint on --merge to hide the course fields for Material, and puts Material right after the main section', function () {
        Blueprint::make('course')->setNamespace('collections.courses')->setContents([
            'tabs' => ['main' => ['sections' => [
                ['fields' => [
                    ['handle' => 'title', 'field' => ['type' => 'text']],
                    ['handle' => 'summary', 'field' => ['type' => 'textarea']],
                ]],
                ['display' => 'Access and pacing', 'fields' => [
                    ['handle' => 'product', 'field' => ['type' => 'text']],
                    ['handle' => 'sequencing_mode', 'field' => ['type' => 'select', 'options' => ['none' => 'None']]],
                    ['handle' => 'drip_mode', 'field' => ['type' => 'select', 'options' => ['none' => 'None']]],
                    ['handle' => 'drip_day_of_month', 'field' => ['type' => 'integer', 'if' => ['drip_mode' => 'equals day_of_month']]],
                    ['handle' => 'own_field', 'field' => ['type' => 'text']],
                ]],
                ['display' => 'Team', 'fields' => [
                    ['handle' => 'team_seats', 'field' => ['type' => 'integer']],
                ]],
            ]]],
        ])->save();

        $this->artisan('courses:install', ['--merge' => true])
            ->expectsOutputToContain('+ material condition drip_mode')
            ->assertSuccessful();

        $contents = Blueprint::find('collections.courses.course')->contents();
        $sections = $contents['tabs']['main']['sections'];
        $fields = collect($sections)->flatMap(fn ($s) => $s['fields'])->keyBy('handle');

        foreach (['product', 'sequencing_mode', 'drip_mode', 'drip_day_of_month', 'team_seats'] as $handle) {
            expect(hiddenForMaterial($fields[$handle]['field']))->toBeTrue($handle);
        }

        expect($fields['drip_day_of_month']['field']['if']['drip_mode'])->toBe('equals day_of_month')
            ->and($fields['own_field']['field'])->not->toHaveKeys(['if', 'unless'])
            ->and(collect($sections[1]['fields'])->pluck('handle')->all())->toContain('body', 'downloads')
            ->and(collect($sections[0]['fields'])->pluck('handle')->all())->toContain('kind');

        // A second run finds nothing more to do.
        $this->artisan('courses:install', ['--merge' => true])->expectsOutputToContain('up to date')->assertSuccessful();
    });

    it('answers the kind of a course entry by slug or by id, for a picker elsewhere', function () {
        $id = $this->makeCourse('cvt-101');

        expect(Courses::kind('baraye'))->toBe('material')
            ->and(Courses::kind($this->material))->toBe('material')
            ->and(Courses::kind($id))->toBe('course')
            ->and(Courses::kind('missing'))->toBeNull();
    });
});

describe('no progress', function () {
    beforeEach(function () {
        $this->makeLesson($this->material, 'notes', ['sort_order' => 1, 'item_type' => 'text']);
        $this->makeLesson($this->material, 'second', ['sort_order' => 2, 'item_type' => 'text', 'drip_after' => 30, 'phase_order' => 2]);
    });

    it('records nothing for a page of a material', function () {
        expect(Courses::acknowledgeLesson($this->buyer, 'baraye', 'notes'))->toBeNull()
            ->and(Courses::setLessonCompletion($this->buyer, 'baraye', 'notes', true))->toBeNull()
            ->and(Courses::completeLesson($this->buyer, 'baraye', 'notes', 'quiz'))->toBeNull()
            ->and(Courses::updateLessonItem($this->buyer, 'baraye', 'notes', ['x' => 1]))->toBeNull()
            ->and(LessonState::query()->count())->toBe(0)
            ->and(Courses::refusalReason($this->buyer, 'baraye', 'notes', 'acknowledge'))->toBe('material');
    });

    it('neither enrolls nor runs a drip clock for a material', function () {
        expect(Courses::enroll($this->buyer, 'baraye'))->toBeNull()
            ->and(Courses::recordBilling($this->buyer, 'baraye', 3))->toBeNull()
            ->and(Courses::pauseDrip($this->buyer, 'baraye'))->toBeNull()
            ->and(Courses::advanceToWeek($this->buyer, 'baraye', 4))->toBeNull()
            ->and(Enrollment::query()->count())->toBe(0);
    });

    it('has no summary or outline, and never locks a page', function () {
        expect(Courses::summary($this->buyer, 'baraye'))->toBeNull()
            ->and(Courses::outline($this->buyer, 'baraye'))->toBeNull()
            ->and(collect(Courses::lessons($this->buyer, 'baraye'))->pluck('is_locked')->all())->toBe([false, false]);
    });

    it('still closes a material by hand, which is access, not progress', function () {
        Courses::suspendAccess($this->buyer, 'baraye');

        expect(Courses::canAccess($this->buyer, 'baraye'))->toBeFalse();
    });

    it('refuses the progress route for a material', function () {
        $this->actingAs($this->buyer);

        $this->postJson(route('statamic.courses.progress'), ['course' => 'baraye', 'lesson' => 'notes', 'action' => 'acknowledge'])
            ->assertStatus(422)
            ->assertJson(['error' => 'material']);
    });

    it('leaves a material off the Course Progress screen', function () {
        $this->makeCourse('cvt-101');

        expect(collect(app(ProgressReport::class)->overview())->pluck('slug')->all())->toBe(['cvt-101']);
    });
});

describe('downloads', function () {
    it('lists the downloads group by group, in the order entered, with the format taken from the file', function () {
        PrivateMedia::$signs = true;

        $material = Courses::material($this->buyer, 'baraye');

        expect(collect($material['downloads'])->map(fn ($d) => $d['group'].'/'.$d['label'].'/'.$d['format'])->all())
            ->toBe(['SATB/Score/PDF', 'SATB/Rehearsal track/MP3', 'SSA/Score/PDF', '/Notes/TXT'])
            ->and(collect($material['download_groups'])->pluck('group')->all())->toBe(['SATB', 'SSA', ''])
            ->and(collect($material['download_groups'][0]['downloads'])->pluck('label')->all())->toBe(['Score', 'Rehearsal track'])
            ->and($material['download_groups'][0]['count'])->toBe(2)
            ->and($material['downloads'][0])->toMatchArray([
                'url' => '/!/private-media/course:baraye/baraye/satb.pdf?signature=test',
                'filename' => 'satb.pdf',
                'extension' => 'pdf',
                'size' => '1 KB',
            ]);
    });

    it('signs downloads only for somebody with access', function () {
        PrivateMedia::$signs = true;

        expect(Courses::material($this->stranger, 'baraye')['downloads'])->toBe([])
            ->and(Courses::material($this->stranger, 'baraye')['download_groups'])->toBe([])
            ->and(Courses::material(null, 'baraye')['downloads'])->toBe([]);
    });

    it('lets private-media hand out a file of the material only to somebody with access', function () {
        $this->app->bind(MediaAccess::class, fn () => new class implements MediaAccess
        {
            public function allows(mixed $user, string $resource, string $path): bool
            {
                return false;
            }
        });

        $access = app(MediaAccess::class);

        expect($access->allows($this->buyer, 'course:baraye', 'baraye/satb.pdf'))->toBeTrue()
            ->and($access->allows($this->stranger, 'course:baraye', 'baraye/satb.pdf'))->toBeFalse()
            ->and($access->allows(null, 'course:baraye', 'baraye/satb.pdf'))->toBeFalse();
    });

    it('leaves out a download whose file is not in the private container', function () {
        PrivateMedia::$signs = true;
        Entry::find($this->material)->set('downloads', [
            ['id' => 'g', 'group' => '', 'files' => [
                ['id' => 'x', 'file' => 'assets::baraye/public.pdf', 'label' => 'Public'],
                ['id' => 'y', 'file' => 'baraye/satb.pdf', 'label' => 'Private'],
            ]],
        ])->save();

        expect(collect(Courses::material($this->buyer, 'baraye')['downloads'])->pluck('label')->all())->toBe(['Private']);
    });

    it('still reads rows saved one file per row, with their own group', function () {
        PrivateMedia::$signs = true;
        Entry::find($this->material)->set('downloads', [
            ['id' => 'a', 'file' => 'baraye/satb.pdf', 'label' => 'Score', 'group' => 'SATB'],
            ['id' => 'b', 'file' => 'baraye/ssa.pdf', 'label' => 'Score', 'group' => 'SSA'],
            ['id' => 'c', 'file' => 'baraye/satb.mp3', 'label' => 'Track', 'group' => 'SATB'],
        ])->save();

        $groups = Courses::material($this->buyer, 'baraye')['download_groups'];

        expect(collect($groups)->pluck('group')->all())->toBe(['SATB', 'SSA'])
            ->and(collect($groups[0]['downloads'])->pluck('label')->all())->toBe(['Score', 'Track']);
    });

    it('renders the body as HTML', function () {
        expect(Courses::material($this->buyer, 'baraye')['body'])->toContain('<p>Sing it slowly.</p>');
    });

    it('gives the public cover as a URL, for a card', function () {
        expect(Courses::material($this->buyer, 'baraye')['cover_url'])->toBe('/assets/baraye/cover.jpg')
            ->and(Courses::course('baraye')['cover_url'])->toBe('/assets/baraye/cover.jpg');
    });
});

describe('images in the text', function () {
    it('signs a private image only for somebody with access', function () {
        PrivateMedia::$signs = true;

        expect(Courses::material($this->buyer, 'baraye')['body'])
            ->toContain('src="/!/private-media/course:baraye/baraye/figure.png?signature=test"');
    });

    it('leaves a private image out for a stranger, a guest, and when it cannot be signed', function () {
        PrivateMedia::$signs = true;

        expect(Courses::material($this->stranger, 'baraye')['body'])->not->toContain('<img')->not->toContain('figure.png')
            ->and(Courses::material(null, 'baraye')['body'])->not->toContain('<img')
            ->and(Courses::material($this->stranger, 'baraye')['body'])->toContain('Sing it slowly.');

        PrivateMedia::$signs = false;

        expect(Courses::material($this->buyer, 'baraye')['body'])->not->toContain('<img')->not->toContain('figure.png');
    });

    it('keeps a public image public', function () {
        Entry::find($this->material)->set('body', [
            ['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['src' => 'asset::assets::baraye/cover.jpg']]]],
        ])->save();

        expect(Courses::material($this->stranger, 'baraye')['body'])->toContain('src="/assets/baraye/cover.jpg"');
    });

    it('signs a private image block on a page of a material, and drops it for a stranger', function () {
        PrivateMedia::$signs = true;
        $page = $this->makeLesson($this->material, 'figures', ['item_type' => 'text', 'blocks' => [
            ['id' => 'i1', 'type' => 'image', 'image' => 'baraye/figure.png', 'caption' => 'Figure 1'],
        ]]);

        $blocks = app(LessonBlocks::class)->for(Entry::find($page), $this->buyer, 'course:baraye');

        expect($blocks[0])->toMatchArray([
            'type' => 'image',
            'url' => '/!/private-media/course:baraye/baraye/figure.png?signature=test',
            'caption' => 'Figure 1',
            'private' => true,
        ])->and(app(LessonBlocks::class)->for(Entry::find($page), null, 'course:baraye'))->toBe([]);
    });

    it('picks text images from the private container, and from the public one with a notice without private-media', function () {
        $body = fn () => Blueprint::find('collections.courses.course')->field('body')->config();

        expect($body()['container'])->toBe('private');

        config()->set('private-media.source.container', null);
        // The site's public container; the bed still has the one named "private".
        config()->set('courses.downloads.container', 'assets');
        $this->artisan('courses:install', ['--force' => true])->assertSuccessful();

        expect($body()['container'])->toBe('assets')
            ->and($body()['instructions'])->toStartWith('Public:');
    });

    it('answers null for a course that is not a material', function () {
        $this->makeCourse('cvt-101');

        expect(Courses::material($this->buyer, 'cvt-101'))->toBeNull()
            ->and(Courses::material($this->buyer, 'missing'))->toBeNull();
    });
});

describe('antlers', function () {
    it('renders a material with its groups for somebody with access', function () {
        PrivateMedia::$signs = true;
        $this->actingAs($this->buyer);

        $out = renderMaterial('{{ courses:material course="baraye" }}{{ title }}|{{ kind }}|{{ download_groups }}[{{ group }}:{{ downloads }}{{ label }},{{ /downloads }}]{{ /download_groups }}{{ /courses:material }}');

        expect($out)->toBe('Baraye|material|[SATB:Score,Rehearsal track,][SSA:Score,][:Notes,]');
    });

    it('renders nothing for a guest or somebody without access', function () {
        expect(renderMaterial('{{ courses:material course="baraye" }}x{{ title }}{{ /courses:material }}'))->toBe('');

        $this->actingAs($this->stranger);
        expect(renderMaterial('{{ courses:material course="baraye" }}x{{ title }}{{ /courses:material }}'))->toBe('');
    });

    it('lists the kind with every course, without progress for a material, and filters by kind', function () {
        $this->makeCourse('cvt-101', ['title' => 'CVT 101']);
        Entitlements::grant(new SubjectReference('user', 'buyer'), 'cvt-101', 'test');
        $this->actingAs($this->buyer);

        expect(renderMaterial('{{ courses }}{{ slug }}:{{ kind }}:{{ if progress }}p{{ else }}-{{ /if }};{{ /courses }}'))
            ->toBe('baraye:material:-;cvt-101:course:p;')
            ->and(renderMaterial('{{ courses kind="material" }}{{ slug }};{{ /courses }}'))->toBe('baraye;')
            ->and(renderMaterial('{{ courses kind="course" }}{{ slug }};{{ /courses }}'))->toBe('cvt-101;');
    });
});
