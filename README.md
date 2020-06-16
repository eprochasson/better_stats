# Stats plugin for LimeSurvey

This plugin aims to expose survey's statistics to be displayed to the user after completion of a survey. The 
goal is to replace the current solution, which is not very flexible or pretty.

Right now, only the plumbing "under the hood" is considered, there is not rendering of the data, it is just made 
available to a view that user of the plugin have to personalize.

The plugin hijacks the "statistic_user" action and replace it with its own, when activated. It gathers all the 
question and answer information and make them available for the view. Only the questions that are made public, for a 
survey whose statistics are made public will be displayed.

At the end of the day, the best use for this is to fork it and only re-use the bit of code extracting all the relevant 
data for a given survey.

It was developped against LimeSurvey version 3.22.4.