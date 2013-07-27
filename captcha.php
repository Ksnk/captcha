<?php
/**
 * Класс для обслуживания капчи в приложении. Информация о содержимом храниться в сессии.
 *
 * При работе с классом - капчей :
 *  -- сессия обязана быть открыта (исключение - генерация картинки по запросу юзера)
 *  -- генерация пути к картинке
 *      <img src="<?=$captcha->pictureName(true)?>">
 *  -- проверка правильности
 *      $captcha->is_correct($_POST['captcha'])
 *  -- инициализация объекта
 *      $captcha=new captcha('form1'); // form1- имя формы
 *
 * Необходима интеграция с .htaccess
 *
 *  RewriteCond %{REQUEST_FILENAME} !-f
 *  RewriteRule upload/captcha/(captcha_.*\.png) captcha/captcha.php?getpicture=$1 [L,QSA]
 *
 *
 * При генерации картинки используется алгоритм от
 * -----------------------
 * w3captcha - php-скрипт для генерации изображений CAPTCHA
 * разработчики: http://w3box.ru
 * -----------------------
 */

class captcha
{

    /**
     * время жизни картинок и сопутствующей информации
     */
    const SESSION_LIFE_TIME = 1200; //60*20
    private static $PICTURE_PATH = '';
    private static $PICTURE_URI = '';
    /**
     * параметры, унаследованные от w3captcha
     */
    private static $chars = "0123456789"; /* набор символов */
    private static $count = 5; /* количество символов */
    private static $width = 100; /* ширина картинки */
    private static $height = 48; /* высота картинки */
    private static $font_size_min = 32; /* минимальная высота символа */
    private static $font_size_max = 32; /* максимальная высота символа */
    private static $font_file = "Comic_Sans_MS.ttf"; /* путь к файлу относительно w3captcha.php */
    private static $char_angle_min = -10; /* максимальный наклон символа влево */
    private static $char_angle_max = 10; /* максимальный наклон символа вправо */
    private static $char_angle_shadow = 5; /* размер тени */
    private static $char_align = 40; /* выравнивание символа по-вертикали */
    private static $start = 5; /* позиция первого символа по-горизонтали */
    private static $interval = 16; /* интервал между началами символов */
    private static $noise = 10; /* уровень шума */
    var $formName = '', $roll = null;

    function __construct($formName, $crc32 = '')
    {
        static $setup_complete;
        /**
         * самое тонкое место продукта - настройка на конкретное расположение
         * файлов-капч и пути к ним
         * Для случая,когда файлы лежат в {root}/captcha/,
         * а картинки в {root}/upload/captcha
         * получится
         *  self::$PICTURE_PATH = realpath(__DIR__ . '/../upload/captcha');
         *  self::$PICTURE_URI = '/upload/captcha/';
         * Сейчас используется подгрузка информации из сессии, смю пример использования
         */
        if (!isset ($setup_complete)
            && !empty($_SESSION['captcha_setup'])
        ) {
            self::setup($_SESSION['captcha_setup']);
            $setup_complete = true;
        }

        if (!empty($crc32)) {
            // конструирование для генерации картинки на лету
            $this->formName = $crc32;
        } else {
            // нормальное конструирование капчи, естественным образом
            $this->formName = sprintf('%u', crc32(
                session_id() . '_' . $formName
            ));
        }
    }

    /**
     * Изменение параметров построения капчи из внешнего мира.
     *
     * @param array $opt - массив с именами - приватными параметрами
     */
    static function setup($opt = array())
    {
        if (!empty($opt) && is_array($opt)) {
            foreach ($opt as $k => $o) {
                if (property_exists(__CLASS__, $k)) {
                    self::$$k = $o;
                }
            }
        }
    }

    /**
     * проверка капчи и сброс картинки.
     * @param $value
     * @return bool
     */
    function is_correct($value)
    {
        $roll =& $this->gimmeRoll(true);
        // хорошее место, чтобы чуть-чуть потормозить. Чистим старье
        $this->clearOldFiles();
        if (!$roll || !isset($roll['word']))
            return false;
        $word = $roll['word'];
        $this->reset();
        // еще одно хорошее место, чтобы чуть-чуть потормозить. Чистим собственный мусор.
        $this->clearOldFiles(true);
        return $value == $word;
    }

    /**
     * clear unusefull files with captcha pictures
     * @param bool $myfiles
     * @return string
     */
    function clearOldFiles($myfiles = false)
    {
        if (empty(self::$PICTURE_PATH)) return;
        if ($myfiles) { // clear own files
            $files = glob(self::$PICTURE_PATH . '/captcha_' . $this->formName . '_*.png');
            if (!empty($files)) {
                foreach ($files as $file)
                    unlink($file);
            }
        } else { // clear old files
            $files = glob(self::$PICTURE_PATH . '/captcha_*.png');
            if (!empty($files)) {
                foreach ($files as $file)
                    if (filemtime($file) + 2 * self::SESSION_LIFE_TIME < time()) {
                        unlink($file);
                    }
            }
        }
    }

    /**
     * clear captcha record, so picture been regenerated at next run.
     * @return string
     */
    function reset()
    {
        $roll =& $this->gimmeRoll();
        unset($roll['word']);
        unset($roll['time']);
    }

    /**
     * create a picture for client request.
     */
    function createImage()
    {
        $roll =& $this->gimmeRoll(true);
        if (!$roll) { // The roll must exists in my session. So - 404
            header("HTTP/1.0 404 Not Found");
            //  header("Status: 404 Not Found");
            exit;
        }
        $expires = $roll['time'] + self::SESSION_LIFE_TIME;
        if (empty($roll['word']) || (time() > $expires)) {
            $roll['time'] = time();
            $expires = $roll['time'] + self::SESSION_LIFE_TIME;

            // so create word
            $num_chars = strlen(self::$chars);
            $roll['word'] = '';
            for ($i = 0; $i < self::$count; $i++) {
                $roll['word'] .= self::$chars[rand(0, $num_chars - 1)];
            }
        }
        $file = $this->pictureName();
        session_write_close();
        if (!file_exists($file)) {
            $this->createPicture($file, $roll['word']);
        }
        header('Pragma: private');
        header('Cache-Control: maxage=' . ($expires - time()));
        header('Expires: ' . gmdate('D, d M Y H:i:s', $expires) . ' GMT');
        header('Content-type:image/png');
        readfile($file);
    }

    /**
     * internal function. Return pointer for captcha record from depths of Session array.
     * @param bool $must_exists - do i need to create this record or just report absence.
     * @return mixed
     */
    private function &gimmeRoll($must_exists = false)
    {
        if (!isset($_SESSION['captcha']) || !is_array($_SESSION['captcha']))
            $_SESSION['captcha'] = array();
        $roll =& $_SESSION['captcha'];
        if (!isset($roll[$this->formName]))
            if ($must_exists)
                return false;
            else
                $roll[$this->formName] = array();
        $roll =& $roll[$this->formName];
        if (!isset($roll['time'])) {
            $roll['time'] = time(); // sign a start of captcha life
        }
        return $_SESSION['captcha'][$this->formName];
    }

    /**
     * return file name or file URI for captcha picture.
     * @param bool $uri - do we need uri?
     * @return string
     */
    function pictureName($uri = false)
    {
        $roll =& $this->gimmeRoll();
        return
            ($uri ? (self::$PICTURE_URI) : (self::$PICTURE_PATH))
            . 'captcha_'
            . $this->formName
            . '_'
            . $roll['time']
            . '.png';
    }

    /**
     * GD magic - picture generation tricks
     * @param $file
     * @param $str
     */
    protected function createPicture($file, $str)
    {
        $image = imagecreatetruecolor(self::$width, self::$height);
        $background_color = imagecolorallocate($image, 255, 255, 255); /* rbg-цвет фона */
        $font_color = imagecolorallocate($image, 32, 64, 96); /* rbg-цвет тени */

        imagefill($image, 0, 0, $background_color);

        for ($i = 0; $i < self::$count; $i++) {
            $char = $str{$i};
            $font_size = rand(self::$font_size_min, self::$font_size_max);
            $char_angle = rand(self::$char_angle_min, self::$char_angle_max);
            imagettftext($image, $font_size, $char_angle, self::$start, self::$char_align, $font_color, self::$font_file, $char);
            imagettftext($image, $font_size, $char_angle + self::$char_angle_shadow * (rand(0, 1) * 2 - 1), self::$start, self::$char_align, $background_color, self::$font_file, $char);
            self::$start += self::$interval;
        }

        if (self::$noise) {
            for ($i = 0; $i < self::$width; $i++) {
                for ($j = 0; $j < self::$height; $j++) {
                    $rgb = imagecolorat($image, $i, $j);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    $k = rand(-self::$noise, self::$noise);
                    $rn = $r + 255 * $k / 100;
                    $gn = $g + 255 * $k / 100;
                    $bn = $b + 255 * $k / 100;
                    if ($rn < 0) $rn = 0;
                    if ($gn < 0) $gn = 0;
                    if ($bn < 0) $bn = 0;
                    if ($rn > 255) $rn = 255;
                    if ($gn > 255) $gn = 255;
                    if ($bn > 255) $bn = 255;
                    $color = imagecolorallocate($image, $rn, $gn, $bn);
                    imagesetpixel($image, $i, $j, $color);
                }
            }
        }
        imagepng($image, $file);
    }
}

if (
    !defined('ROOT_PATH') // one more thin place. On my mind this mean no any files except this/
    && isset($_GET['getpicture']) // just a last sign
) {
    // so we know nobody would start session for us.
    session_start();

    // last check we properly called
    if (!preg_match('/captcha_([^_]+)_([^_]+)\.png/'
        , $_SERVER['REQUEST_URI'], $m)
    ) {
        header("HTTP/1.0 404 Not Found");
        exit;
    }

    // so let's create picture
    $_captcha = new captcha('', $m[1]);
    $_captcha->createImage();
    exit;
}