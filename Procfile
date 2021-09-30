postdeploy: php artisan migrate --force
scheduler: php artisan queue:work --queue=emails,default && php artisan schedule:daemon
