found a bug on creating a task [SOLVED]

```

Fatal error: Uncaught Exception: Database query failed in /var/www/html/includes/Database.php:49 Stack trace: #0 /var/www/html/includes/Database.php(58): Database->query('SELECT t.*, p.n...', Array) #1 /var/www/html/task_detail.php(17): Database->fetchOne('SELECT t.*, p.n...', Array) #2 {main} thrown in /var/www/html/includes/Database.php on line 49
```

and tryig to view a task, browser shows

```

Fatal error: Uncaught Exception: Database query failed in /var/www/html/includes/Database.php:49 Stack trace: #0 /var/www/html/includes/Database.php(58): Database->query('SELECT t.*, p.n...', Array) #1 /var/www/html/task_detail.php(17): Database->fetchOne('SELECT t.*, p.n...', Array) #2 {main} thrown in /var/www/html/includes/Database.php on line 49
```

seems the task was created, but it has problem on selecting data.