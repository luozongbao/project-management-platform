Php project management to manage my personal project that include these features

## Technology Stack
0. development environment use docker
1. Nginx
2. php 8.3
3. Mariadb
4. phpmailer

## Features
1. User must login
2. Registration with Name, Username, Email (On registration succsess send reset password link)
3. Forget password/Reset Password
4. Dashboard shows
    - Overall Completion Percentage (Average All project completion percentage)
    - Number of Projects
    - Number of uncompleted tasks
    - 
5. Project List (Add new Project)
    - Project Name
    - Completion Date
    - Expected Completion Date
    - Project Status
    - Overall Project Completion Percentage
    - Number of Uncompleted Tasks
    - Actions (Update button)
6. Project Detail (For Add/update modes)
    - (Add/Edit mode)Project Name
    - (Add/Edit mode)Project Description
    - (Add/Edit mode)Responsible Person (Default user)
    - (Add/Edit mode)Expect Comletion Date
    - [On Read Mode]Project Completion Percentage (show average completion status)
    - [On Read Mode] 
        - Number of Contact Persons
        - Button link to Project Contact Person List 
    - [On Read Mode]
        - Number of Tasks
        - Button link to Tasks List
    - [On Update Mode]red Delete Button at the bottom of the page
7. Project Contact List (With button Add New Contact on top, Upate option shows on each contact row)
8. Contact Details (for Add/update Modes)
    - Project Name (Required, Default Proejct Name)
    - Name (Required)
    - Description
    - Mobile
    - Email
    - Wechat
    - Line
    - Facebook
    - LinkedIn
    - [Update Mode]red Delete Button at the bottom of the page
11. Task List page (with button Add New Task)
    - Task Name
    - Task Responsible Person
    - Task Contact Person
    - Expected Completion Date
    - Completion Date
    - Task Completion Percentage
    - Task Status
10. Task/Sub Task Detail(Task Recursive) (this page is used for task/subtask Add/update Modes) 
    - Project Name
    - [Incase is Subtask] Parent Task
    - Task Name
    - Task Description
    - Task Responsible Person (Default user, Dropdown to choose or Add new Contact Person)
    - Task Contact Person
    - Completion Date
    - Expected Completion Date
    - Task Status
    - Task Completion Percentage (If no subtask user can input, if there are subtasks use average completion status)
    - Sub Tasks List
        - Task Name
        - Task Responsible Person
        - Task Contact Person
        - Task Completion Percentage
        - Expected Completion Date
        - Completion Date
        - Task Status
        - Task Actions (Complete, Edit Task)
    - [Update Mode]red Delete Button at the bottom of the  page
11. Install.php create config.php (First call index.php if no config.php then redirect to install.php)
    - Step 1. Setup/Create Database
        - Database name
        - Database user
        - Database password
        - Database Host
    - Step 2. (Used to send reset password link)
        - SMTP Server
        - SMTP Port
        - SMTP Protocol
        - SMTP user
        - SMTP Password 
    - On completion create config.php and rediect to user Dashboard

## Pages
0. Installation Page
1. Loginpage
2. Overall Project Dashboard
3. Project List
4. Project Detail (Add/Update)
5. Task List
6. Task Detail (Add/Update)
7. Project Contact List
8. Project Contact Detail
9. Contact List (Show all Contact from All Project)

## Others
make sure to save datetime as UTC but show date time in user timezone in frontend


