postdeploy: php artisan migrate --force
scheduler: php artisan queue:work --queue=high,default && php artisan schedule:daemon
