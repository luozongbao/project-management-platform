I want to work on security matter.  

I dont want user to beale to access, any file listed in the root folder unless they clicked in the application links.  file like _smtp_test.php, .htaccess .env, .env.example, config.php these files need to be protected. somethime I think it is better for us to just used slugs.

Also Let's remove http://<domain>/client/ but use the root url (http://<domain>) for client to put the project ID to access their project.  but on this root page create a login link to redirect for redirecting project owners to login page.

on delete project, conclick delete should popup a confirmation popup.  project manager needs to put project ID inorder to confirm project deletion.

is this possible? help me analyze and write issue files in docs/issues for dev to implement these requirents.