# psImageRegenCLI
Prestashop images regeneration for command line.

This is based on functions from prestashop 1.6.1.4, tested and works on 1.6.1.11

Note: Watermark images are generated at end, after all images were resized.

Usage:
- Make a backup of your img folder (eg. like "tar -cvf imagesbackup.tar img"),
- Copy regenImagesCLI.php file to prestashop base dir,
- in command line change permissions by chmod +x regenImagesCLI.php,
- run script by ./regenImagesCLI.php,
- for first run say (Y)es to delete all images and wait,
- if You want to break script press CTRL + C,
- after work is done You should delete this file from your web server.
