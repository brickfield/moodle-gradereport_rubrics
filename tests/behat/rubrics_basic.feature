@gradereport @gradereport_rubrics
Feature: Selecting an assignment option for a rubrics report
  In order to generate a rubrics report
  As a teacher
  I need to check that the rubrics report is correctly displayed

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname | email |
      | teacher1 | Teacher | 1 | teacher1@example.com |
      | student1 | Student | 1 | student10@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activity" exists:
      | activity                          | assign                      |
      | course                            | C1                          |
      | section                           | 1                           |
      | name                              | Test assignment 1 name      |
      | intro                             | Test assignment description |
      | assignfeedback_comments_enabled   | 1                           |
      | assignfeedback_editpdf_enabled    | 1                           |
      | advancedgradingmethod_submissions | rubric                      |
    And I log in as "teacher1"
    And I am on "Course 1" course homepage
    When I go to "Test assignment 1 name" advanced grading definition page
    # Defining a rubric.
    And I set the following fields to these values:
      | Name | Assignment 1 rubric |
      | Description | Rubric test description |
    And I define the following rubric:
      | TMP Criterion 1 | TMP Level 11 | 11 | TMP Level 12 | 12 |
      | TMP Criterion 2 | TMP Level 21 | 21 | TMP Level 22 | 22 |
      | TMP Criterion 3 | TMP Level 31 | 31 | TMP Level 32 | 32 |
      | TMP Criterion 4 | TMP Level 41 | 41 | TMP Level 42 | 42 |
    # Checking that only the last ones are saved.
    And I define the following rubric:
      | Criterion 1 | Level 11 | 1  | Level 12 | 20 | Level 13 | 40 | Level 14  | 50  |
      | Criterion 2 | Level 21 | 10 | Level 22 | 20 | Level 23 | 30 |           |     |
      | Criterion 3 | Level 31 | 5  | Level 32 | 20 |          |    |           |     |
    And I press "Save rubric and make it ready"
    # Grading two students.
    Then I am on the "Test assignment 1 name" "assign activity" page
    And I wait "5" seconds
    And I go to "Student 1" "Test assignment 1 name" activity advanced grading page
    And I grade by filling the rubric with:
      | Criterion 1 | 50 | Very good |
      | Criterion 2 | 10 | Mmmm, you can do it better |
      | Criterion 3 | 5 | Not good |
    And I complete the advanced grading form with these values:
      | Feedback comments | In general... work harder... |

  @javascript
  Scenario: A teacher views a rubrics report.
    Given I am logged in as "teacher1"
    And I am on "Course 1" course homepage
    When I navigate to "View > Rubrics report" in the course gradebook
    And I set the field "Select assignment" to "Test assignment 1 name"
    And I press "Go"
    Then "Student 1" row "Grade" column of "generaltable" should contain "65"
