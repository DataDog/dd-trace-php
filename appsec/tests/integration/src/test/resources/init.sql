CREATE TABLE users (
   id INT AUTO_INCREMENT PRIMARY KEY,
   username VARCHAR(255) NOT NULL,
   password VARCHAR(255) NOT NULL,
   email VARCHAR(255),
   status VARCHAR(50) DEFAULT 'inactive',
   last_login DATETIME
);

INSERT INTO users (username, password, email, status) VALUES
  ('admin', 'adminpass', 'admin@example.com', 'active'),
  ('user1', 'user1pass', 'user1@example.com', 'active'),
  ('user2', 'user2pass', 'user2@example.com', 'inactive');
