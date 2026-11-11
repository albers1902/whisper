-- Down for 0002: fjerner brugere og login-forsøg (F001). Brugerne skal oprettes igen efter en ny up.
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS users;
