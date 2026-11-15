-- Last time a member's browser checked in while its tab was visible. Used to skip support emails to members who are
-- already looking at the site. One row per member.
CREATE TABLE member_presence (
    user_id     INT UNSIGNED NOT NULL PRIMARY KEY,
    seen_at     DATETIME(3)  NOT NULL,
    CONSTRAINT fk_presence_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
