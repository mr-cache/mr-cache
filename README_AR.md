# MrCache

تخزين استعلامات Laravel وEloquent مؤقتاً باستخدام Redis، مع مدد صلاحية وسياسات تخزين وإبطال واضحة.

[English](README.md) · [Packagist](https://packagist.org/packages/mrcache/mrcache) · [ملاحظات الترقية](CHANGELOG.md)

## التوافق

| Laravel / Illuminate | الحد الأدنى لـPHP |
| --- | --- |
| 10 | 8.1 |
| 11–12 | 8.2 |
| 13 | 8.3 |

تعتمد الحزمة على Predis بالإصدار `^2.0`، وتدعم phpredis اختيارياً. توافق الحزمة لا يمدد الدعم الأمني للإطار؛ راجع تنبيهات الاعتماديات عند استخدام إصدارات قديمة.

## التثبيت

يوثّق هذا الملف فرع التطوير `5.x`. لتثبيت هذا الفرع:

```sh
composer require mrcache/mrcache:5.x-dev
php artisan vendor:publish --provider="MrCache\Providers\MrCacheServiceProvider" --tag="mrcache-config"
```

لتثبيت إصدار موسوم، استخدم `composer require mrcache/mrcache` واتبع README الخاص بذلك الإصدار.

اضبط Redis وبادئة مستقلة للتطبيق والبيئة قبل التفعيل:

```dotenv
MRCACHE_ENABLED=true
MRCACHE_PREFIX=myapp_production
MRCACHE_REDIS_CLIENT=predis
MRCACHE_REDIS_HOST=127.0.0.1
MRCACHE_REDIS_PORT=6379
MRCACHE_TTL=60
```

الكاش معطّل افتراضياً. استخدم `php artisan mrcache:doctor` لفحص الاتصال والأوامر المطلوبة.

## البداية السريعة

```php
use Illuminate\Database\Eloquent\Model;
use MrCache\Traits\CacheableModel;

class Post extends Model
{
    use CacheableModel;

    protected $cacheTTL = 60;
}

$posts = Post::where('is_published', true)->withCustomTTL(30)->get();
$fresh = Post::withoutCaching()->find(1);
```

أولوية TTL من الأدنى للأعلى: الإعداد العام، ثم مدة النموذج، ثم سياسته المركزية، ثم سياسة الاستعلام، ثم `withCustomTTL()` الصريحة. الصفر يتجاوز التخزين، وتُرفض القيم السالبة أو المتجاوزة للحد.

## السياسات والسياق

```php
$posts = Post::query()->where('tenant_id', $tenantId)
    ->cacheContext(['tenant' => $tenantId, 'permission_version' => $permissionVersion])
    ->withCustomTTL(60)->refreshAhead(5)->get();

$fresh = Post::query()->cacheConsistency('database')->find($id);
$decision = Post::query()->explainCache();
```

تعزل `cacheContext()` هوية الكاش، لكنها لا ترشّح الصفوف أو تفرض الصلاحيات. ضمّن شروط المستأجر والتفويض في SQL. يتجاوز وضع `database` الكاش، بينما يعتمد `bounded` على TTL والإبطال المرصود. لا تفعّل `cache()` التخزين وحدها.

تُضبط السياسات المركزية في `mrcache.policies[Model::class]`. تقبل سياسة الاستعلام `ttl` و`consistency` و`max_payload_bytes` و`refresh_ahead_seconds` و`relations`. التجديد المبكر تزامني، وعتبته أقل من TTL، ولا يقدم بيانات منتهية الصلاحية.

## الاستعلامات والعلاقات

تُخزّن قراءات النماذج المؤهلة عبر `get()` و`first()` و`find()` الخصائص الخام، ثم تعيد تهيئتها بواسطة Eloquent. تبقى التحويلات والتواريخ والحقول المخفية ونوع المجموعة محفوظة. لا تُخزّن النتائج الفارغة، والحد الافتراضي للحمولة واحد MiB.

تُدعم قوائم IN ذات القيم البسيطة. تتجاوز المعاملات والأقفال والربط والاستعلامات الفرعية والتعبيرات الخام والدوال ونسخ القراءة والأشكال غير المدعومة الكاش. تبقى دوال التجميع المباشرة وcursor على قاعدة البيانات.

```php
$posts = Post::with('children')->cacheRelations(['children'])->get();
```

تحتاج العلاقات قائمة سماح صريحة للمستوى الأول. تُخزّن النماذج المرتبطة المؤهلة التي تستخدم `CacheableModel` صفوفها منفصلة، وترث سياق الأب ولا تتجاوز مدة تخزينه. تتجاوز العلاقات المتداخلة وقراءات pivot والربط الكاش.

## الإبطال والاتساق

افتراضياً، تبطل أحداث SQL غير SELECT المرصودة عبر Laravel والالتزامات الخارجية للمعاملات النطاق كله. تمنع الأجيال الملتقطة قارئاً بطيئاً من إعادة تعبئة الجيل الحالي بعد الإبطال. أوامر التفريغ حسب الصف أو النموذج شاملة أيضاً. تنتهي المدخلات القديمة حسب TTL؛ التفريغ ليس حذفاً مادياً للبيانات.

```php
use MrCache\Services\CacheInvalidator;

$ok = app(CacheInvalidator::class)->invalidateAll();
$ok = app(CacheInvalidator::class)->invalidateTables(['posts']);
```

افحص النتيجة المنطقية عند فشل Redis في الوضع غير الصارم. يحتاج وضع الجداول الاختياري خريطة كاملة في `mrcache.invalidation.writes` لتبعيات الجداول الفعلية، بما فيها المحفزات والحذف المتتابع. يرجع الإبطال للشمول عند الكتابة غير المعروفة والالتزام الخارجي. تصرّح `cacheDependsOn()` بتبعيات فعلية إضافية. استخدم بادئة جديدة عند تغيير وضع الإبطال.

الكتابة في قاعدة البيانات والإبطال في Redis عمليتان منفصلتان. قد تترك الكتابة الخارجية أو PDO أو تعطيل الأحداث أو دوال SELECT ذات الآثار الجانبية أو انهيار العملية أو فشل الإبطال نتائج قديمة حتى انتهاء الصلاحية أو تفريغ ناجح. اقرأ قاعدة البيانات مباشرة عندما لا يقبل التطبيق هذه النافذة.

### الإبطال الدائم

```sh
php artisan mrcache:install --outbox
# راجع الترحيل المنشور ثم نفّذه على كل قاعدة بيانات مشاركة.
php artisan migrate
```

```php
use MrCache\Services\InvalidationOutbox;

app(InvalidationOutbox::class)->transaction(null, function ($connection) use ($id) {
    $connection->table('posts')->where('id', $id)->update(['is_published' => true]);
}, ['posts']);
```

جدول `php artisan mrcache:outbox --batch=100` لمعالجة الإبطال المعلق. المشاركة صريحة وتسجل الإبطال في معاملة قاعدة البيانات نفسها، وتبقى السجلات الفاشلة قابلة للمحاولة. هذا إبطال نهائي وليس قراءة ذرية بين النظامين. لا تُشغّل الحزمة الترحيلات أو العمال تلقائياً.

## Redis والتشغيل

تستخدم التعبئة الباردة أقفالاً ذات ملكية ومهلة وانتظار محدودين. يقرأ المنتظر الذي انتهت مهلته قاعدة البيانات دون نشر نتيجة، ولا يستطيع المالك المنتهي استبدال نتيجة خلفه. تبقى سعة قاعدة البيانات وضبط معدل الطلبات ضروريين أثناء الأعطال.

عند فشل Redis في الوضع غير الصارم يعود التنفيذ لقاعدة البيانات ويُعطّل العميل حتى إعادة إنشائه؛ أعد تشغيل العمال طويلي العمر بعد التعافي. يرمي الوضع الصارم استثناءً، وقد تكون كتابة قاعدة البيانات قد نجحت بالفعل. تبقى أخطاء البرمجة وقاعدة البيانات ظاهرة بصورة مستقلة.

اضبط `redis.scheme=tls` لاستخدام TLS، و`redis.username` لمستخدم ACL. خيارات الشهادات الاختيارية هي `cafile` و`capath` و`peer_name` و`local_cert` و`local_pk`؛ لا يمكن تعطيل التحقق من الشهادة واسم الخادم. لا يُدعم Cluster أو Sentinel.

تتضمن الحمولة الخصائص الخام المحددة، بما فيها الحقول المخفية، ولا تحمل توقيعاً للمصادقة. احمِ الوصول إلى Redis وسياسة الاحتفاظ ببياناته. تُعامل الحمولة التالفة أو القديمة أو الكبيرة أو غير القابلة لترميز JSON كغياب للكاش أو يُتجاوز تخزينها.

```sh
php artisan mrcache:doctor
php artisan mrcache:flush
php artisan mrcache:stats  # يتطلب store_metrics=true
```

يبث `CacheEvent` نتائج وأسباباً ومدد قراءة منقحة دون SQL أو قيم مرتبطة أو صفوف أو سياق خام. يمكن للتطبيق ربطها بأدوات المراقبة. تتوفر أدوات اختبارات للمستهلك عبر `MrCache\Testing\InteractsWithMrCache`.

## التطوير والقياس

```sh
composer validate --strict
composer install
composer lint
MRCACHE_TEST_REDIS_PORT=6379 composer test
MRCACHE_TEST_REDIS_PORT=6379 MRCACHE_TEST_REDIS_CLIENT=phpredis composer test
composer audit
MRCACHE_TEST_REDIS_PORT=6379 vendor/bin/phpunit benchmarks/PerformanceTest.php
```

تستخدم الاختبارات SQLite وبادئات Redis معزولة. تُتجاوز اختبارات Redis صراحةً عند غياب متغير المنفذ. تحتاج اختبارات TLS أيضاً `MRCACHE_TEST_TLS_PORT` و`MRCACHE_TEST_TLS_CA` و`MRCACHE_TEST_TLS_PASSWORD_FILE`؛ تُنشأ ملفات مؤقتة للاختبار بواسطة `tests/Support/tls-fixture.sh`. يتطلب اختبار التزامن pcntl. تعرّف CI بيئات PHP/Laravel متوافقة للإصدارات 10–13 والعميلين.

تشمل نتائج القياس القراءة الباردة والدافئة والكتابة والمفتاح الساخن المتزامن. قِس حمل تطبيقك وقاعدة بياناته الفعلية؛ التخزين المؤقت لا يضمن التفوق على استعلام سريع.

الترخيص: [MIT](LICENSE).
