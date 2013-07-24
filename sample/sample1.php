<?php
/**
 * sample of CAPTCHA usage
 */

// for debugging. Comment this if you really sure
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
/**
 * place pictures just here. no mater the cost
 */
$_SESSION['captcha_setup'] = array(
    'PICTURE_PATH' => __DIR__ . '/',
    'PICTURE_URI' => '/projects/captcha/sample/',
);

include("../captcha.php");

if ($_SERVER['REQUEST_METHOD'] == "POST") {
    if (empty($_POST['handle'])) exit(555);
    $_captcha = new captcha($_POST['handle']);
    if (isset($_POST['reload_captcha_x'])
        || isset($_POST['reload_captcha'])
    ) {
        $_captcha->reset();
    } elseif ($_captcha->is_correct($_POST['captcha'])) {
        echo 'Captcha is correct<br>';
    } else {
        echo 'Incorrect captcha<br>';
    }
}
?>
<style>
    .cimage {
        width: 100px;
        height: 48px;
    }
</style>
<h3>form1</h3>

<?php $_captcha = new captcha('form1'); ?>
<form method="POST" action="">
    <input type="hidden" name="handle" value="form1">
    <label>
        Type the digits
        <input type="text" name="captcha">
    </label>
    <img class="cimage" src="<?= $_captcha->pictureName(true) ?>">
    <input type="submit" value="reload" name="reload_captcha">
    <input type="submit">
</form>

<h3>form2</h3>

<?php $_captcha = new captcha('form2'); ?>
<form method="POST" action="">
    <input type="hidden" name="handle" value="form2">
    <label>
        Type the digits
        <input type="text" name="captcha">
    </label>
    <input type="image" class="cimage" src="<?= $_captcha->pictureName(true) ?>"
           title="Click to reload captcha" name="reload_captcha">
    <input type="submit">
</form>